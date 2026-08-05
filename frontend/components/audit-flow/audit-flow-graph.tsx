import React, { useCallback, useMemo, useEffect } from "react";
import { ReactFlow, Controls, Background, MiniMap, BackgroundVariant, useReactFlow } from "@xyflow/react";
import "@xyflow/react/dist/style.css";
import { useAuditFlowStore } from "@/store/use-audit-flow-store";
import { nodeTypes } from "./custom-nodes";
import { NodeInspector } from "./node-inspector";
import type { AuditNode } from "@/lib/audit-flow/dag-builder";

const STATUS_BADGE_CLASS = {
  live: "bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-400 dark:border-emerald-500/20",
  historical: "bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:border-slate-700",
} as const;

function AutoFitView({ nodeCount }: { nodeCount: number }) {
  const { fitView } = useReactFlow();

  useEffect(() => {
    if (nodeCount > 0) {
      const timer = setTimeout(() => {
        fitView({ padding: 0.2, duration: 800 });
      }, 50);
      return () => clearTimeout(timer);
    }
  }, [nodeCount, fitView]);

  return null;
}

export function AuditFlowGraph() {
  const { nodes, edges, onNodesChange, onEdgesChange, setSelectedNode, status } = useAuditFlowStore();

  const onNodeClick = useCallback(
    (_: React.MouseEvent, node: AuditNode) => {
      setSelectedNode(node);
    },
    [setSelectedNode],
  );

  const onPaneClick = useCallback(() => {
    setSelectedNode(null);
  }, [setSelectedNode]);

  const defaultEdgeOptions = useMemo(() => ({
    type: "smoothstep",
    style: { strokeWidth: 2, stroke: "#cbd5e1" },
    animated: status === "live",
  }), [status]);

  return (
    <div className="w-full h-full relative">
      {status === "error" && (
        <div className="absolute top-4 left-1/2 -translate-x-1/2 z-20 bg-red-50 text-red-900 border border-red-200 px-4 py-2 rounded-lg text-sm font-medium shadow-sm dark:bg-red-950/50 dark:border-red-900/50 dark:text-red-200">
          Error conectando a telemetría. Reintentando...
        </div>
      )}

      {(status === "live" || status === "historical") && (
        <div
          className={`absolute left-4 top-4 z-20 flex items-center gap-2 rounded-full border px-3 py-1.5 text-[10px] font-bold uppercase tracking-widest shadow-sm transition-colors ${STATUS_BADGE_CLASS[status]}`}
        >
          {status === "live" && (
            <span className="h-1.5 w-1.5 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.8)]" />
          )}
          {status === "live" ? "En vivo" : "Histórico"}
        </div>
      )}

      <style>{`
        .dark .react-flow__controls-button {
          background-color: #0f172a !important;
          border-bottom-color: #1e293b !important;
          fill: #cbd5e1 !important;
        }
        .dark .react-flow__controls-button:hover {
          background-color: #1e293b !important;
        }
        .dark .react-flow__controls {
          box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
          border: 1px solid #1e293b;
        }
        .dark .react-flow__minimap {
          background-color: #020617 !important;
          border: 1px solid #1e293b !important;
        }
        .dark .react-flow__minimap-mask {
          fill: rgba(255, 255, 255, 0.08) !important;
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
          gap={16}
          size={1.5}
          className="opacity-50 dark:opacity-30"
        />
        <Controls showInteractive={false} />
        <MiniMap
          zoomable
          pannable
          nodeClassName={(node: AuditNode) => {
            if (node.data?.state === "failed" || node.data?.state === "rejected") {
              return "!bg-red-500 dark:!bg-red-600";
            }
            if (node.data?.state === "completed") {
              return "!bg-emerald-500 dark:!bg-emerald-600";
            }
            if (node.data?.state === "running") {
              return "!bg-blue-500 dark:!bg-blue-600";
            }
            return "!bg-slate-300 dark:!bg-slate-700";
          }}
          className="border-slate-200 shadow-sm !rounded-lg overflow-hidden"
          maskColor="rgba(0,0,0,0.1)"
          maskStrokeColor="transparent"
          maskStrokeWidth={1}
        />
        <AutoFitView nodeCount={nodes.length} />
      </ReactFlow>

      <NodeInspector />
    </div>
  );
}
