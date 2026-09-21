import { useCallback, useEffect, useMemo } from "react";
import {
  Background,
  BackgroundVariant,
  Controls,
  MiniMap,
  ReactFlow,
  useReactFlow,
} from "@xyflow/react";
import { Activity, AlertTriangle, History } from "lucide-react";
import "@xyflow/react/dist/style.css";

import type { AuditNode } from "@/lib/audit-flow/dag-builder";
import { useAuditFlowStore } from "@/store/use-audit-flow-store";
import { nodeTypes } from "@/components/audit-flow/custom-nodes";

import { NodeInspector } from "./node-inspector";

function AutoFitView({ nodeCount }: { nodeCount: number }) {
  const { fitView } = useReactFlow();

  useEffect(() => {
    if (nodeCount === 0) return;

    const timer = window.setTimeout(() => {
      void fitView({ padding: 0.2, duration: 200 });
    }, 50);

    return () => window.clearTimeout(timer);
  }, [nodeCount, fitView]);

  return null;
}

export function AuditFlowGraph() {
  const {
    nodes,
    edges,
    onNodesChange,
    onEdgesChange,
    setSelectedNode,
    status,
  } = useAuditFlowStore();

  const onNodeClick = useCallback(
    (_event: React.MouseEvent, node: AuditNode) => setSelectedNode(node),
    [setSelectedNode],
  );

  const onPaneClick = useCallback(() => setSelectedNode(null), [setSelectedNode]);

  const defaultEdgeOptions = useMemo(
    () => ({
      type: "smoothstep",
      style: {
        strokeWidth: 1.5,
        stroke: "oklch(0.69 0.035 247 / 0.42)",
      },
      animated: status === "live",
    }),
    [status],
  );

  return (
    <div className="relative h-full w-full">
      {status === "error" ? (
        <div className="absolute left-1/2 top-4 z-20 flex -translate-x-1/2 items-center gap-2 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs font-medium text-rose-200">
          <AlertTriangle className="h-3.5 w-3.5" aria-hidden="true" />
          Telemetría interrumpida, reintentando
        </div>
      ) : null}

      {status === "live" || status === "historical" ? (
        <div className="absolute left-4 top-4 z-20 inline-flex items-center gap-2 rounded-md border border-border bg-popover/90 px-2.5 py-1.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">
          {status === "live" ? (
            <Activity className="h-3.5 w-3.5 text-emerald-300" aria-hidden="true" />
          ) : (
            <History className="h-3.5 w-3.5 text-sky-300" aria-hidden="true" />
          )}
          {status === "live" ? "En vivo" : "Histórico"}
        </div>
      ) : null}

      <style>{`
        .react-flow__controls {
          overflow: hidden;
          border: 1px solid var(--border);
          border-radius: 0.375rem;
          box-shadow: none;
        }
        .react-flow__controls-button {
          background: var(--surface-raised);
          border-bottom-color: var(--border);
          fill: var(--muted-foreground);
        }
        .react-flow__controls-button:hover {
          background: var(--surface-hover);
          fill: var(--foreground);
        }
        .react-flow__minimap {
          overflow: hidden;
          border: 1px solid var(--border);
          border-radius: 0.375rem;
          background: var(--surface-raised);
        }
      `}</style>

      <ReactFlow<AuditNode>
        nodes={nodes}
        edges={edges}
        onNodesChange={onNodesChange}
        onEdgesChange={onEdgesChange}
        onNodeClick={onNodeClick}
        onPaneClick={onPaneClick}
        nodeTypes={nodeTypes}
        defaultEdgeOptions={defaultEdgeOptions}
        fitView
        fitViewOptions={{ padding: 0.2 }}
        minZoom={0.1}
        maxZoom={1.5}
        preventScrolling={false}
        zoomOnScroll={false}
      >
        <Background
          variant={BackgroundVariant.Dots}
          gap={18}
          size={1}
          color="oklch(0.69 0.035 247 / 0.22)"
        />
        <Controls showInteractive={false} />
        <MiniMap
          zoomable
          pannable
          nodeColor={(node: AuditNode) => {
            if (node.data?.state === "failed") return "oklch(0.7 0.18 18)";
            if (node.data?.state === "rejected") return "oklch(0.8 0.15 78)";
            if (node.data?.state === "completed") return "oklch(0.74 0.16 158)";
            if (node.data?.state === "running") return "oklch(0.75 0.14 244)";
            return "oklch(0.46 0.03 247)";
          }}
          maskColor="oklch(0.11 0.018 252 / 0.55)"
          maskStrokeColor="transparent"
          maskStrokeWidth={1}
        />
        <AutoFitView nodeCount={nodes.length} />
      </ReactFlow>

      <NodeInspector />
    </div>
  );
}
