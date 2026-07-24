import { create } from "zustand";
import {
  type Edge,
  type EdgeChange,
  type NodeChange,
  applyEdgeChanges,
  applyNodeChanges,
} from "@xyflow/react";

import {
  type AuditNode,
  type AuditNodeState,
  type AuditTelemetryEvent,
  buildInitialDag,
} from "@/lib/audit-flow/dag-builder";

interface AuditFlowState {
  nodes: AuditNode[];
  edges: Edge[];
  selectedNode: AuditNode | null;
  status: "idle" | "connecting" | "live" | "historical" | "error";
  mode: "audit" | "job";
  onNodesChange: (changes: NodeChange[]) => void;
  onEdgesChange: (changes: EdgeChange[]) => void;
  setSelectedNode: (node: AuditNode | null) => void;
  setStatus: (status: AuditFlowState["status"]) => void;
  setMode: (mode: AuditFlowState["mode"]) => void;
  setGraph: (nodes: AuditNode[], edges: Edge[]) => void;
  processTelemetryEvent: (event: AuditTelemetryEvent) => void;
}

const X_START = 100;
const Y_START = 100;
const X_SPACING = 300;
const Y_SPACING = 150;

const PHASE_COLUMN: Record<string, number> = {
  orchestration: 0,
  download: 1,
  extraction: 2,
  normalization: 3,
  policy: 4,
  aggregation: 5,
};

const PHASE_LABEL: Record<string, string> = {
  orchestration: "Orquestación",
  download: "Descarga",
  extraction: "Extracción",
  normalization: "Normalización",
  policy: "Reglas",
  aggregation: "Agregación",
};

const initialDag = buildInitialDag();

export const useAuditFlowStore = create<AuditFlowState>((set, get) => ({
  nodes: initialDag.nodes,
  edges: initialDag.edges,
  selectedNode: null,
  status: "idle",
  mode: "audit",

  onNodesChange: (changes) => {
    set({ nodes: applyNodeChanges(changes, get().nodes) as AuditNode[] });
  },

  onEdgesChange: (changes) => {
    set({ edges: applyEdgeChanges(changes, get().edges) });
  },

  setSelectedNode: (node) => set({ selectedNode: node }),
  setStatus: (status) => set({ status }),
  setMode: (mode) => set({ mode }),
  setGraph: (nodes, edges) => set({ nodes, edges }),

  processTelemetryEvent: (event) => {
    set((state) => {
      const nodes = [...state.nodes];
      const edges = [...state.edges];
      const nodeId = state.mode === "job" ? event.node_id : buildNodeId(event);
      const nodeState = eventState(event.event_type);
      let targetNodeIndex = nodes.findIndex((node) => node.id === nodeId);

      if (targetNodeIndex === -1 && state.mode !== "job") {
        const newNode = buildNode(event, nodeId, nodeState, nodes);
        nodes.push(newNode);
        targetNodeIndex = nodes.length - 1;
        appendIncomingEdges(edges, nodes, event, nodeId);
      }

      if (targetNodeIndex === -1) {
        return { nodes, edges };
      }

      const targetNode = nodes[targetNodeIndex];
      let newNodeState = nodeState;
      let newMetrics = targetNode.data.metrics;
      let newTaskStates = targetNode.data.taskStates;

      if (state.mode === "job") {
        const taskId = event.document_id ?? event.audit_id ?? "unknown";
        newTaskStates = { ...(targetNode.data.taskStates ?? {}) };
        newTaskStates[taskId] = eventState(event.event_type);

        let total = 0;
        let completed = 0;
        let failed = 0;
        let rejected = 0;
        for (const tState of Object.values(newTaskStates)) {
          total++;
          if (tState === "completed") completed++;
          if (tState === "failed") failed++;
          if (tState === "rejected") rejected++;
        }

        newMetrics = { total, completed, failed, rejected };

        const processed = completed + failed + rejected;
        if (total > 0 && processed >= total) {
          newNodeState = failed > 0 ? "failed" : rejected > 0 ? "rejected" : "completed";
        } else if (total > 0) {
          newNodeState = "running";
        }
      }

      nodes[targetNodeIndex] = {
        ...targetNode,
        data: {
          ...targetNode.data,
          state: newNodeState,
          taskStates: newTaskStates,
          metrics: newMetrics,
          worker: stringMeta(event, "worker") ?? targetNode.data.worker,
          durationMs: state.mode === "job" ? undefined : numberMeta(event, "duration_ms") ?? targetNode.data.durationMs,
          details: eventDetails(state.mode, targetNode.data.details, event.meta),
        },
      };

      return {
        nodes,
        edges: newNodeState === "running"
          ? edges
          : edges.map((edge) => (edge.target === nodeId ? { ...edge, animated: false } : edge)),
      };
    });
  },
}));

function buildNodeId(event: AuditTelemetryEvent): string {
  return event.document_id ? `${event.document_id}-${event.node_id}` : event.node_id;
}

function eventState(eventType: AuditTelemetryEvent["event_type"]): AuditNodeState {
  if (eventType === "started") {
    return "running";
  }

  return eventType;
}

function buildNode(
  event: AuditTelemetryEvent,
  nodeId: string,
  state: AuditNodeState,
  nodes: AuditNode[],
): AuditNode {
  const documentRow = event.document_id ? getDocumentRowIndex(nodes, event.document_id) : 0;
  const column = PHASE_COLUMN[event.node_id] ?? 0;

  return {
    id: nodeId,
    position: {
      x: X_START + X_SPACING * column,
      y: event.document_id ? Y_START + documentRow * Y_SPACING : Y_START,
    },
    data: {
      label: PHASE_LABEL[event.node_id] ?? event.node_id,
      state,
    },
    type: event.document_id ? "documentNode" : "auditNode",
  };
}

function appendIncomingEdges(
  edges: Edge[],
  nodes: AuditNode[],
  event: AuditTelemetryEvent,
  nodeId: string,
): void {
  if (event.node_id === "download") {
    pushEdgeIfMissing(edges, "orchestration", nodeId, true);
    return;
  }

  if (event.document_id && event.node_id === "extraction") {
    pushEdgeIfMissing(edges, `${event.document_id}-download`, nodeId, true);
    return;
  }

  if (event.document_id && event.node_id === "normalization") {
    pushEdgeIfMissing(edges, `${event.document_id}-extraction`, nodeId, true);
    return;
  }

  if (event.document_id && event.node_id === "policy") {
    pushEdgeIfMissing(edges, `${event.document_id}-normalization`, nodeId, true);
    return;
  }

  if (event.node_id === "aggregation") {
    nodes
      .filter((node) => node.id.endsWith("-policy"))
      .forEach((node) => pushEdgeIfMissing(edges, node.id, nodeId, false));
  }
}

function pushEdgeIfMissing(edges: Edge[], source: string, target: string, animated: boolean): void {
  if (edges.some((edge) => edge.source === source && edge.target === target)) {
    return;
  }

  edges.push({
    id: `e-${source}-${target}`,
    source,
    target,
    animated,
  });
}

function getDocumentRowIndex(nodes: AuditNode[], documentId: string): number {
  const documentIds: string[] = [];
  for (const node of nodes) {
    const match = node.id.match(/^(.*)-(download|extraction|normalization|policy)$/);
    if (match && !documentIds.includes(match[1])) {
      documentIds.push(match[1]);
    }
  }

  let index = documentIds.indexOf(documentId);
  if (index === -1) {
    documentIds.push(documentId);
    index = documentIds.length - 1;
  }
  return index;
}

function stringMeta(event: AuditTelemetryEvent, key: string): string | undefined {
  const value = event.meta?.[key];
  return typeof value === "string" && value !== "" ? value : undefined;
}

function numberMeta(event: AuditTelemetryEvent, key: string): number | undefined {
  const value = event.meta?.[key];
  return typeof value === "number" && Number.isFinite(value) ? value : undefined;
}

function eventDetails(
  mode: AuditFlowState["mode"],
  currentDetails: Record<string, unknown> | undefined,
  meta: Record<string, unknown> | null | undefined,
): Record<string, unknown> | undefined {
  const eventMeta = meta && Object.keys(meta).length > 0 ? { ...meta } : undefined;

  if (mode === "job") {
    return eventMeta;
  }

  if (!currentDetails && !eventMeta) {
    return undefined;
  }

  return { ...(currentDetails ?? {}), ...(eventMeta ?? {}) };
}
