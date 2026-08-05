import type { Edge, Node } from "@xyflow/react";

export type AuditNodeState = "pending" | "running" | "completed" | "failed" | "rejected";

export type AuditTelemetryEvent = {
  audit_id: string;
  job_id?: string | null;
  dis_det_nro?: string | null;
  document_id?: string | null;
  node_id: string;
  event_type: "started" | "completed" | "failed" | "rejected";
  status?: string | null;
  timestamp: string;
  meta?: Record<string, unknown> | null;
};

export type AuditNodeData = {
  label: string;
  state: AuditNodeState;
  durationMs?: number;
  worker?: string;
  error?: string;
  details?: Record<string, unknown>;
  taskStates?: Record<string, AuditNodeState>;
  metrics?: {
    total: number;
    completed: number;
    failed: number;
    rejected: number;
  };
};

export type AuditNode = Node<AuditNodeData>;

type AuditDocumentDecision = {
  documentName?: string | null;
  approved?: boolean | null;
  observation?: string | null;
  payload?: Record<string, unknown> | null;
};

type AuditHistoryResult = {
  EstadoDetallado?: string | null;
  documentDecisions?: AuditDocumentDecision[];
};

type AuditTimingLike = {
  pipeline?: Record<string, number | null | undefined> | null;
} | null | undefined;

const X_START = 100;
const Y_START = 100;
const X_SPACING = 300;
const Y_SPACING = 150;

const DOCUMENT_PHASES = [
  { key: "download", label: "Descarga", column: 1 },
  { key: "extraction", label: "Extracción", column: 2 },
  { key: "normalization", label: "Normalización", column: 3 },
  { key: "policy", label: "Reglas", column: 4 },
] as const;

export function buildInitialDag(): { nodes: AuditNode[]; edges: Edge[] } {
  return {
    nodes: [
      {
        id: "orchestration",
        position: { x: X_START, y: Y_START },
        data: { label: "Orquestación", state: "pending" },
        type: "auditNode",
      },
    ],
    edges: [],
  };
}

export function buildAggregatedJobDag(): { nodes: AuditNode[]; edges: Edge[] } {
  const defaultMetrics = () => ({ total: 0, completed: 0, failed: 0, rejected: 0 });
  const nodes: AuditNode[] = [
    {
      id: "orchestration",
      position: { x: X_START, y: Y_START },
      data: { label: "Orquestación", state: "pending", metrics: defaultMetrics() },
      type: "auditNode",
    },
    {
      id: "download",
      position: { x: X_START + X_SPACING, y: Y_START },
      data: { label: "Descarga", state: "pending", metrics: defaultMetrics() },
      type: "auditNode",
    },
    {
      id: "extraction",
      position: { x: X_START + X_SPACING * 2, y: Y_START },
      data: { label: "Extracción", state: "pending", metrics: defaultMetrics() },
      type: "auditNode",
    },
    {
      id: "normalization",
      position: { x: X_START + X_SPACING * 3, y: Y_START },
      data: { label: "Normalización", state: "pending", metrics: defaultMetrics() },
      type: "auditNode",
    },
    {
      id: "policy",
      position: { x: X_START + X_SPACING * 4, y: Y_START },
      data: { label: "Reglas", state: "pending", metrics: defaultMetrics() },
      type: "auditNode",
    },
    {
      id: "aggregation",
      position: { x: X_START + X_SPACING * 5, y: Y_START },
      data: { label: "Agregación", state: "pending", metrics: defaultMetrics() },
      type: "auditNode",
    },
  ];
  const edges: Edge[] = [
    { id: "e-orch-down", source: "orchestration", target: "download", animated: true },
    { id: "e-down-ext", source: "download", target: "extraction", animated: true },
    { id: "e-ext-norm", source: "extraction", target: "normalization", animated: true },
    { id: "e-norm-pol", source: "normalization", target: "policy", animated: true },
    { id: "e-pol-agg", source: "policy", target: "aggregation", animated: true },
  ];
  return { nodes, edges };
}

export function buildDagFromHistory(
  result: AuditHistoryResult,
  timings: AuditTimingLike,
): { nodes: AuditNode[]; edges: Edge[] } {
  const nodes: AuditNode[] = [
    {
      id: "orchestration",
      position: { x: X_START, y: Y_START },
      data: {
        label: "Orquestación",
        state: "completed",
        durationMs: numberValue(timings?.pipeline?.created_to_started_ms),
      },
      type: "auditNode",
    },
  ];
  const edges: Edge[] = [];
  const documents = result.documentDecisions ?? [];

  if (documents.length === 0) {
    nodes.push({
      id: "aggregation",
      position: { x: X_START + X_SPACING, y: Y_START },
      data: {
        label: "Agregación",
        state: result.EstadoDetallado === "failed" ? "failed" : "completed",
      },
      type: "auditNode",
    });
    edges.push({ id: "e-orch-agg", source: "orchestration", target: "aggregation", animated: false });
    return { nodes, edges };
  }

  documents.forEach((documentDecision, index) => {
    const documentId = `doc-${index}`;
    const documentName = documentDecision.documentName || `Documento ${index + 1}`;
    const documentState: AuditNodeState = documentDecision.approved ? "completed" : "rejected";
    const rowY = Y_START + index * Y_SPACING;

    DOCUMENT_PHASES.forEach((phase, phaseIndex) => {
      const nodeId = `${documentId}-${phase.key}`;
      const isRejectedPolicy = documentState === "rejected" && phase.key === "policy";
      const state: AuditNodeState = isRejectedPolicy ? "rejected" : "completed";

      nodes.push({
        id: nodeId,
        position: { x: X_START + X_SPACING * phase.column, y: rowY },
        data: {
          label: `${phase.label} - ${documentName}`,
          state,
          details: isRejectedPolicy ? (documentDecision.payload ?? { observation: documentDecision.observation ?? null }) : undefined,
        },
        type: "documentNode",
      });

      edges.push({
        id: phaseIndex === 0 ? `e-orch-${nodeId}` : `e-${documentId}-${DOCUMENT_PHASES[phaseIndex - 1].key}-${nodeId}`,
        source: phaseIndex === 0 ? "orchestration" : `${documentId}-${DOCUMENT_PHASES[phaseIndex - 1].key}`,
        target: nodeId,
        animated: false,
      });
    });

    edges.push({
      id: `e-${documentId}-policy-agg`,
      source: `${documentId}-policy`,
      target: "aggregation",
      animated: false,
    });
  });

  nodes.push({
    id: "aggregation",
    position: { x: X_START + X_SPACING * 5, y: Y_START + ((documents.length - 1) * Y_SPACING) / 2 },
    data: {
      label: "Agregación y Persistencia",
      state: isSuccessfulStatus(result.EstadoDetallado) ? "completed" : "failed",
      durationMs: numberValue(timings?.pipeline?.rules_to_completed_ms),
    },
    type: "auditNode",
  });

  return { nodes, edges };
}

function numberValue(value: number | null | undefined): number | undefined {
  return typeof value === "number" && Number.isFinite(value) ? value : undefined;
}

function isSuccessfulStatus(status?: string | null): boolean {
  return status === "completed" || status === "manual_review";
}
