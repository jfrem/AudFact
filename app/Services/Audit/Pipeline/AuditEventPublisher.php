<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Env;
use Core\Logger;
use Core\RedisClient;
use Core\RedisUnavailableException;
use RuntimeException;

class AuditEventPublisher
{
    public const STREAM_INBOX_PRIORITY       = 'audit.inbox.priority';
    public const STREAM_INBOX_BATCH          = 'audit.inbox.batch';
    public const STREAM_DOCUMENTS_PRIORITY   = 'audit.documents.priority';
    public const STREAM_DOCUMENTS_BATCH      = 'audit.documents.batch';
    public const STREAM_PERSISTENCE_PRIORITY = 'audit.persistence.priority';
    public const STREAM_PERSISTENCE_BATCH    = 'audit.persistence.batch';
    public const STREAM_RESULTS_PRIORITY     = 'audit.results.priority';
    public const STREAM_RESULTS_BATCH        = 'audit.results.batch';
    public const STREAM_BATCH_INBOX          = 'audit.batch.inbox';
    public const STREAM_SEMANTIC_PRIORITY    = 'audit.semantic.priority';
    public const STREAM_SEMANTIC_BATCH       = 'audit.semantic.batch';

    public const GROUP_ORCHESTRATOR = 'orchestrator';
    public const GROUP_DOWNLOADERS  = 'downloaders';
    public const GROUP_EXTRACTORS   = 'extractors';
    public const GROUP_NORMALIZERS  = 'normalizers';
    public const GROUP_POLICY       = 'policy';
    public const GROUP_PERSISTENCE  = 'persistence';
    public const GROUP_BATCH        = 'batch-workers';
    public const GROUP_SEMANTIC     = 'semantic';

    private const DEFAULT_STREAM_MAXLEN = 100000;
    private const WORK_GROUPS = [
        self::STREAM_INBOX_PRIORITY => [self::GROUP_ORCHESTRATOR],
        self::STREAM_INBOX_BATCH => [self::GROUP_ORCHESTRATOR],
        self::STREAM_DOCUMENTS_PRIORITY => [self::GROUP_DOWNLOADERS, self::GROUP_EXTRACTORS, self::GROUP_NORMALIZERS, self::GROUP_POLICY],
        self::STREAM_DOCUMENTS_BATCH => [self::GROUP_DOWNLOADERS, self::GROUP_EXTRACTORS, self::GROUP_NORMALIZERS, self::GROUP_POLICY],
        self::STREAM_BATCH_INBOX => [self::GROUP_BATCH],
        self::STREAM_SEMANTIC_PRIORITY => [self::GROUP_SEMANTIC],
        self::STREAM_SEMANTIC_BATCH => [self::GROUP_SEMANTIC],
    ];

    private RedisClient $redis;
    private ?int $streamMaxLen;

    public function __construct(?RedisClient $redis = null)
    {
        $this->redis = $redis ?? RedisClient::getInstance();
        $raw = Env::get('AUDIT_STREAM_MAXLEN', (string) self::DEFAULT_STREAM_MAXLEN);
        $parsed = is_numeric($raw) ? (int) $raw : self::DEFAULT_STREAM_MAXLEN;
        $this->streamMaxLen = $parsed > 0 ? $parsed : null;
    }

    public function publish(AuditEvent $event): string
    {
        $stream = self::streamForEvent($event);

        return $this->publishTo($stream, $event);
    }

    public function publishDeadLetter(AuditEvent $event): string
    {
        if ($event->eventType !== AuditEvent::TYPE_DEAD_LETTER) {
            throw new \InvalidArgumentException('publishDeadLetter requiere evento de tipo dead_letter');
        }

        $result = $this->redis->eval(
            self::DEAD_LETTER_LUA,
            [self::dlqStream(), self::deadLetterReceiptKey($event->parentEventId ?? $event->eventId)],
            [$event->toJson()]
        );
        if (!is_string($result) || !preg_match('/^\d+-\d+$/', $result)) {
            throw new RuntimeException('Redis no confirmó la publicación durable de dead_letter');
        }
        return $result;
    }

    public static function deadLetterReceiptKey(string $eventId): string
    {
        return "event:{$eventId}:dead_letter";
    }

    // El recibo no expira mientras el mensaje espera cierre/ACK. El consumidor
    // le aplica retención después del ACK. XADD y recibo comparten operación.
    private const DEAD_LETTER_LUA = <<<'LUA'
        local receipt = redis.call('GET', KEYS[2])
        if receipt then return receipt end
        local id = redis.call('XADD', KEYS[1], '*', 'event', ARGV[1])
        redis.call('SET', KEYS[2], id)
        return id
    LUA;

    private function publishTo(string $stream, AuditEvent $event): string
    {
        try {
            $id = isset(self::WORK_GROUPS[$stream])
                ? $this->redis->eval(self::WORK_STREAM_LUA, [$stream], [
                    $event->toJson(), $this->streamMaxLen ?? 0, json_encode(self::WORK_GROUPS[$stream], JSON_THROW_ON_ERROR),
                ])
                : $this->redis->xAdd($stream, ['event' => $event->toJson()], $this->streamMaxLen);
        } catch (RedisUnavailableException $e) {
            Logger::error('AuditEventPublisher: Redis falló publicando evento', [
                'stream' => $stream,
                'event_type' => $event->eventType,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('No se pudo publicar el evento en Redis', 0, $e);
        }

        if (!is_string($id) || preg_match('/^\d+-\d+$/D', $id) !== 1) {
            Logger::error('AuditEventPublisher: Redis no disponible', [
                'stream' => $stream,
                'event_type' => $event->eventType,
            ]);
            throw new RuntimeException('Redis no disponible para publicar eventos');
        }

        Logger::info('AuditEventPublisher: evento publicado', [
            'stream' => $stream,
            'event_type' => $event->eventType,
            'event_id' => $event->eventId,
            'audit_id' => $event->auditId,
            'job_id' => $event->jobId,
            'document_id' => $event->documentId,
            'stream_id' => $id,
        ]);

        return $id;
    }

    // MAXLEN es una meta: ningún grupo puede perder entradas no entregadas o en PEL.
    // Cada publicación poda como máximo 1000 entradas ya consumidas por todos los grupos.
    public function trimWorkStreams(): void
    {
        foreach (self::WORK_GROUPS as $stream => $groups) {
            $this->redis->eval(self::WORK_STREAM_LUA, [$stream], ['', $this->streamMaxLen ?? 0,
                json_encode($groups, JSON_THROW_ON_ERROR), 'trim_only']);
        }
    }

    /** Evento terminal con recibo atómico; los reintentos del finalizador no duplican XADD. */
    public function publishTerminalOnce(AuditEvent $event): string
    {
        if (!in_array($event->eventType, [AuditEvent::TYPE_AUDIT_FAILED, AuditEvent::TYPE_AUDIT_COMPLETED,
            AuditEvent::TYPE_BATCH_COMPLETED, AuditEvent::TYPE_BATCH_COMPLETED_ERR], true)) {
            throw new \InvalidArgumentException('terminal_event_required');
        }
        $value = $this->redis->eval('local types={"stream","string","hash"}; for i,k in ipairs(KEYS) do local ty=redis.call("TYPE",k).ok; if ty~="none" and ty~=types[i] then return redis.error_reply("terminal_key_type_invalid") end end; local old=redis.call("GET",KEYS[2]); if old then return cjson.decode(old).stream_id end; local event=cjson.decode(ARGV[1]); local id=redis.call("XADD",KEYS[1],"MAXLEN","~",ARGV[2],"*","event",ARGV[1]); local t=redis.call("TIME"); redis.call("SET",KEYS[2],cjson.encode({stream_id=id,audit_id=event.audit_id,job_id=event.job_id,published_at_ms=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)})); if ARGV[3]=="1" then redis.call("HINCRBY",KEYS[3],"terminal_failures",1) end; return id',
            [self::streamForEvent($event), 'audit:terminal:v2:sent:' . $event->eventId, 'telemetry:async_metrics'],
            [$event->toJson(), $this->streamMaxLen ?? 100000, $event->eventType === AuditEvent::TYPE_AUDIT_FAILED ? 1 : 0]);
        if (!is_string($value)) {
            throw new \RuntimeException('terminal_publication_invalid');
        }
        return $value;
    }

    private const WORK_STREAM_LUA = <<<'LUA'
        if ARGV[4]=='trim_only' and redis.call('EXISTS',KEYS[1])==0 then return 0 end
        local function less(a, b)
            local am, as = string.match(a, '^(%d+)%-(%d+)$')
            local bm, bs = string.match(b, '^(%d+)%-(%d+)$')
            if #am ~= #bm then return #am < #bm end
            if am ~= bm then return am < bm end
            if #as ~= #bs then return #as < #bs end
            return as < bs
        end
        local target = tonumber(ARGV[2])
        if target > 0 and redis.call('XLEN', KEYS[1]) > target then
            local groups = redis.call('XINFO', 'GROUPS', KEYS[1])
            local frontier = nil
            local found = {}
            for _, group in ipairs(groups) do
                local name, delivered
                for i = 1, #group, 2 do
                    if group[i] == 'name' then name = group[i + 1] end
                    if group[i] == 'last-delivered-id' then delivered = group[i + 1] end
                end
                found[name] = true
                if not frontier or less(delivered, frontier) then frontier = delivered end
                local pending = redis.call('XPENDING', KEYS[1], name)
                if tonumber(pending[1]) > 0 and less(pending[2], frontier) then frontier = pending[2] end
            end
            for _, required in ipairs(cjson.decode(ARGV[3])) do
                if not found[required] then frontier = nil; break end
            end
            if frontier and frontier ~= '0-0' then
                redis.call('XTRIM', KEYS[1], 'MINID', '~', frontier, 'LIMIT', 1000)
            end
        end
        if ARGV[4]=='trim_only' then return 1 end
        return redis.call('XADD', KEYS[1], '*', 'event', ARGV[1])
    LUA;

    public static function isPriorityEvent(AuditEvent $event): bool
    {
        // Cada señal explícita basta por sí sola; jobId no determina el carril.
        if (($event->payload['source'] ?? '') === 'single') {
            return true;
        }

        if (($event->payload['is_priority'] ?? false) === true) {
            return true;
        }

        return false;
    }

    public static function streamForEvent(AuditEvent $event, ?bool $isPriority = null): string
    {
        $priority = $isPriority ?? self::isPriorityEvent($event);

        return self::streamForEventType($event->eventType, $priority);
    }

    public static function streamForEventType(string $eventType, bool $isPriority = false): string
    {
        return match ($eventType) {
            AuditEvent::TYPE_AUDIT_CREATED,
            AuditEvent::TYPE_BATCH_CREATED       => $isPriority ? self::STREAM_INBOX_PRIORITY : self::STREAM_INBOX_BATCH,
            AuditEvent::TYPE_DOCUMENT_REGISTERED,
            AuditEvent::TYPE_DOCUMENT_DOWNLOADED,
            AuditEvent::TYPE_DOCUMENT_EXTRACTED,
            AuditEvent::TYPE_DOCUMENT_REJECTED,
            AuditEvent::TYPE_SEMANTIC_RESOLVED,
            AuditEvent::TYPE_DOCUMENT_NORMALIZED => $isPriority ? self::STREAM_DOCUMENTS_PRIORITY : self::STREAM_DOCUMENTS_BATCH,
            AuditEvent::TYPE_SEMANTIC_REQUESTED => $isPriority ? self::STREAM_SEMANTIC_PRIORITY : self::STREAM_SEMANTIC_BATCH,
            AuditEvent::TYPE_AUDIT_COMPLETED,
            AuditEvent::TYPE_AUDIT_FAILED,
            AuditEvent::TYPE_BATCH_COMPLETED,
            AuditEvent::TYPE_BATCH_COMPLETED_ERR => $isPriority ? self::STREAM_RESULTS_PRIORITY : self::STREAM_RESULTS_BATCH,
            AuditEvent::TYPE_BATCH_REQUESTED     => self::STREAM_BATCH_INBOX,
            default => throw new \InvalidArgumentException("event_type sin stream asignado: {$eventType}"),
        };
    }

    public static function dlqStream(): string
    {
        $value = Env::get('AUDIT_DLQ_STREAM', 'audit.dlq');
        return is_string($value) && $value !== '' ? $value : 'audit.dlq';
    }
}
