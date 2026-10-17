<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\DocumentPolicyEngine;
use App\Services\Audit\Pipeline\SemanticEvaluationStore;
use App\Services\Audit\SemanticCandidateCollector;
use App\Services\Audit\SemanticMatchResolver;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;

final class SemanticEvaluationStoreTest extends TestCase
{
    public function testSeventeenIndependentRowsProduceSixteenPlusOneWithoutOmittingPairs(): void
    {
        // Arrange:
        $collector = new SemanticCandidateCollector();
        for ($i = 0; $i < 17; $i++) {
            $collector->evaluate('expected-' . $i, 'actual-' . $i, ['semantic_row_index' => $i]);
        }
        $store = new SemanticEvaluationStore($this->createStub(RedisClient::class));

        // Act:
        $plan = $store->buildPlan($this->snapshot(), $collector->candidates());
        $waves = SemanticEvaluationStore::waves($plan);
        $copy = $plan;
        $events = SemanticEvaluationStore::events($plan);
        $replay = SemanticEvaluationStore::events($copy);

        // Assert:
        self::assertSame([16, 1], array_map('count', $waves));
        self::assertCount(17, array_unique(array_merge(...array_map(static fn(array $wave): array => array_column($wave, 'pair_id'), $waves))));
        self::assertCount(2, $events);
        self::assertSame(array_map(static fn(AuditEvent $event): string => $event->eventId, $events),
            array_map(static fn(AuditEvent $event): string => $event->eventId, $replay));
        self::assertSame([], SemanticEvaluationStore::events($plan));
    }

    public function testLastCandidateIsVisitedAndPositiveMatchStopsOnlyItsRow(): void
    {
        // Arrange:
        $collector = new SemanticCandidateCollector();
        foreach (['first', 'second', 'last'] as $actual) {
            $collector->evaluate('expected', $actual);
        }
        $plan = (new SemanticEvaluationStore($this->createStub(RedisClient::class)))->buildPlan($this->snapshot(), $collector->candidates());
        $visited = [];

        // Act:
        foreach ([false, false, true] as $match) {
            $wave = SemanticEvaluationStore::waves($plan)[0];
            $pair = $wave[0];
            $visited[] = $pair['actual'];
            $plan['pairs'][$pair['pair_id']]['state'] = 'resolved';
            $plan['pairs'][$pair['pair_id']]['result'] = ['is_match' => $match, 'reasoning' => 'Synthetic fixture'];
            $plan = SemanticEvaluationStore::advance($plan);
        }

        // Assert:
        self::assertSame(['first', 'second', 'last'], $visited);
        self::assertSame('ready', $plan['state']);
        self::assertSame('semantic_match', array_values($plan['rows'])[0]['state']);
        self::assertSame([], SemanticEvaluationStore::waves($plan));
    }

    public function testDuplicatePairServesSeveralRowsAndContextOrModelInvalidatesCache(): void
    {
        // Arrange:
        $collector = new SemanticCandidateCollector();
        $collector->evaluate('expected', 'actual', ['semantic_row_index' => 0, 'document_context' => 'A']);
        $collector->evaluate('expected', 'actual', ['semantic_row_index' => 1, 'document_context' => 'A']);
        $store = new SemanticEvaluationStore($this->createStub(RedisClient::class));
        $snapshot = $this->snapshot();

        // Act:
        $first = $store->buildPlan($snapshot, $collector->candidates());
        $snapshot['versions']['model'] = 'different-model';
        $other = $store->buildPlan($snapshot, $collector->candidates());
        $firstPair = array_values($first['pairs'])[0];
        $otherPair = array_values($other['pairs'])[0];

        // Assert:
        self::assertCount(1, $first['pairs']);
        self::assertCount(2, $first['rows']);
        self::assertCount(1, SemanticEvaluationStore::waves($first)[0]);
        self::assertNotSame(SemanticEvaluationStore::cacheKey($first, $firstPair), SemanticEvaluationStore::cacheKey($other, $otherPair));
        self::assertNotSame($first['source_fingerprint'], $other['source_fingerprint']);
    }

    public function testPlanningCallsNoRemoteResolverAndResumePreservesExistingPolicy(): void
    {
        // Arrange:
        $remote = new class implements SemanticMatchResolver {
            public int $calls = 0;
            public function evaluate(string $expected, string $actual, array $context = []): array
            {
                $this->calls++;
                return ['is_match' => false, 'reasoning' => 'Synthetic fixture'];
            }
        };
        $engine = new DocumentPolicyEngine($remote);
        $state = ['tipo_documento' => 'DISPENSA', 'fields_config' => [[
            'campoNombre' => 'NombrePaciente', 'tipoCampo' => 'S', 'tipoDato' => 'person_name', 'severity' => 'alta',
        ]], 'fuente_verdad' => ['header' => ['NombrePaciente' => 'PERSONA ALFA'], 'items' => []]];
        $payload = ['tipo_documento' => 'DISPENSA', 'document_quality' => 'legible',
            'fields_normalized' => ['NombrePaciente' => ['valor' => 'SUJETO BETA', 'presente' => true, 'estadoExtraccion' => 'FOUND']]];

        // Act:
        $planning = $engine->plan($state, $payload, 'fixture');
        $callsAfterPlanning = $remote->calls;
        $resolved = [];
        foreach ($planning['candidates'] as $pair) {
            $resolved[$pair['pair_id']] = ['is_match' => false, 'reasoning' => 'Synthetic fixture'];
        }
        $resumed = $engine->evaluateResolved(['document_state' => $state, 'normalized_payload' => $payload, 'fac_nro' => 'fixture'], $resolved);
        $direct = $engine->evaluate($state, $payload, 'fixture');

        // Assert:
        self::assertTrue($planning['requires_semantics']);
        self::assertSame(0, $callsAfterPlanning);
        self::assertSame($direct['hallazgos'], $resumed['hallazgos']);
        self::assertSame($direct['document_decision'], $resumed['document_decision']);
    }

    private function snapshot(): array
    {
        $event = AuditEvent::create(AuditEvent::TYPE_DOCUMENT_NORMALIZED,
            'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa', documentId: 'existing');
        $record = $event->toArray();
        $record['event_id'] = 'bbbbbbbb-bbbb-4bbb-abbb-bbbbbbbbbbbb';
        $record['timestamp'] = '2026-10-08T00:00:00Z';
        return ['source_event' => $record, 'versions' => ['model' => 'fixture', 'rules' => '1', 'normalizer' => '1', 'extractor' => '1']];
    }
}
