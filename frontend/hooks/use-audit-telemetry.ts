import { useEffect } from "react";

import {
  type AuditTelemetryEvent,
  buildInitialDag,
  buildAggregatedJobDag,
  buildDagFromHistory,
} from "@/lib/audit-flow/dag-builder";
import { useAuditFlowStore } from "@/store/use-audit-flow-store";
import { getAuditResultDetail } from "@/lib/api/audfact";

const UUID_V4_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const MAX_RECONNECT_DELAY_MS = 10_000;

export function useAuditTelemetry(auditId?: string | null, uuid?: string, mode: "audit" | "job" = "audit") {
  const { setGraph, setStatus, setMode, processTelemetryEvent } = useAuditFlowStore();

  useEffect(() => {
    let sse: EventSource | null = null;
    let isActive = true;
    let reconnectTimer: number | undefined;
    let reconnectAttempts = 0;
    const initialDag = mode === "job" ? buildAggregatedJobDag() : buildInitialDag();

    setMode(mode);
    setGraph(initialDag.nodes, initialDag.edges);

    function loadInitialState() {
      if (!isActive) {
        return;
      }

      try {
        if (!auditId) {
          setStatus("idle");
          return;
        }

        setStatus("connecting");

        const streamId = resolveStreamId(auditId ?? "", uuid);
        if (!streamId) {
          void fetchHistoricalDag(auditId);
          return;
        }

        openStream(streamId);
      } catch {
        scheduleReconnect();
      }
    }

    async function fetchHistoricalDag(facNro: string) {
      try {
        const detail = await getAuditResultDetail(facNro);
        if (!isActive) return;

        const { nodes, edges } = buildDagFromHistory(detail, {
          pipeline: detail.timings?.total_elapsed_ms ? {
            created_to_started_ms: detail.timings.queue_wait_ms,
            rules_to_completed_ms: detail.timings.processing_duration_ms,
          } : undefined
        });

        setGraph(nodes, edges);
        setStatus("historical");
      } catch (err) {
        if (!isActive) return;
        setStatus("error");
        setGraph([{
          id: "error-node",
          position: { x: 100, y: 100 },
          data: { label: String(err), state: "failed" },
          type: "auditNode"
        }], []);
      }
    }

    function openStream(streamId: string) {
      sse?.close();
      sse = new EventSource(`/api/backend/audit/${streamId}/flow-stream`);
      sse.onopen = () => {
        reconnectAttempts = 0;
        setStatus("live");
      };
      sse.addEventListener("connected", () => undefined);
      sse.addEventListener("telemetry", handleTelemetryEvent);
      sse.addEventListener("timeout", scheduleReconnect);
      sse.onerror = scheduleReconnect;
    }

    function handleTelemetryEvent(event: MessageEvent<string>) {
      try {
        processTelemetryEvent(JSON.parse(event.data) as AuditTelemetryEvent);
      } catch {
        setStatus("error");
      }
    }

    function scheduleReconnect() {
      if (!isActive) {
        return;
      }

      sse?.close();
      sse = null;
      setStatus("connecting");

      if (reconnectTimer !== undefined) {
        window.clearTimeout(reconnectTimer);
      }

      const delayMs = Math.min(1000 * 2 ** reconnectAttempts, MAX_RECONNECT_DELAY_MS);
      reconnectAttempts += 1;
      reconnectTimer = window.setTimeout(() => {
        reconnectTimer = undefined;
        void loadInitialState();
      }, delayMs);
    }

    loadInitialState();

    return () => {
      isActive = false;
      if (reconnectTimer !== undefined) {
        window.clearTimeout(reconnectTimer);
      }
      sse?.close();
    };
  }, [auditId, uuid, mode, setGraph, setStatus, setMode, processTelemetryEvent]);
}

function resolveStreamId(auditId: string, uuid?: string): string | null {
  if (uuid && UUID_V4_PATTERN.test(uuid)) {
    return uuid;
  }

  if (auditId && UUID_V4_PATTERN.test(auditId)) {
    return auditId;
  }

  return null;
}
