"use client";

import * as React from "react";
import { Calendar, CheckCircle2, Clock } from "lucide-react";
import { cn } from "@/lib/utils";

type TimelineNode = {
  label: string;
  date: string | null;
  icon?: React.ElementType;
};

function formatDate(raw: unknown): string | null {
  if (raw == null || String(raw).trim() === "") return null;
  const str = String(raw).trim();
  // Handle ISO / SQL dates — display as yyyy-MM-dd
  const match = str.match(/^(\d{4}[-/]\d{2}[-/]\d{2})/);
  return match ? match[1].replace(/\//g, "-") : str;
}

export function DispensationDatesTimeline({
  fechaFormula,
  fechaAutorizacion,
  fechaEntrega,
  numeroAutorizacion,
}: {
  fechaFormula: unknown;
  fechaAutorizacion: unknown;
  fechaEntrega: unknown;
  numeroAutorizacion: unknown;
}) {
  const nodes: TimelineNode[] = [
    { label: "Fórmula", date: formatDate(fechaFormula), icon: Calendar },
    { label: "Autorización", date: formatDate(fechaAutorizacion), icon: Clock },
    { label: "Entrega", date: formatDate(fechaEntrega), icon: CheckCircle2 },
  ];

  const autNum = numeroAutorizacion != null && String(numeroAutorizacion).trim() !== ""
    ? String(numeroAutorizacion)
    : null;

  return (
    <div className="space-y-3">
      {/* Timeline nodes */}
      <div className="relative space-y-0">
        {nodes.map((node, i) => {
          const isLast = i === nodes.length - 1;
          const hasDate = node.date !== null;
          const Icon = node.icon ?? Calendar;

          return (
            <div key={node.label} className="relative flex items-start gap-3 pb-4 last:pb-0">
              {/* Vertical line connector */}
              {!isLast && (
                <div
                  className="absolute left-[11px] top-[24px] h-[calc(100%-12px)] w-px bg-white/10"
                  aria-hidden="true"
                />
              )}

              {/* Node circle */}
              <div
                className={cn(
                  "relative z-10 flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-full border",
                  hasDate
                    ? isLast
                      ? "border-emerald-500/40 bg-emerald-500/15 text-emerald-400"
                      : "border-sky-500/30 bg-sky-500/10 text-sky-400"
                    : "border-white/10 bg-white/[0.04] text-slate-500"
                )}
              >
                <Icon className="h-3 w-3" />
              </div>

              {/* Content */}
              <div className="min-w-0 pt-0.5">
                <p className="text-[12px] font-medium text-slate-300">{node.label}</p>
                <p
                  className={cn(
                    "mt-0.5 text-[12px] tabular-nums",
                    hasDate ? "text-white" : "text-slate-500"
                  )}
                >
                  {node.date ?? "N/D"}
                </p>
              </div>
            </div>
          );
        })}
      </div>

      {/* Authorization number */}
      {autNum && (
        <div className="rounded-lg border border-white/[0.06] bg-white/[0.02] px-3 py-2">
          <p className="text-[11px] font-medium uppercase tracking-[0.1em] text-slate-500">
            No. Autorización
          </p>
          <p className="mt-0.5 text-[13px] font-medium tabular-nums text-white">
            {autNum}
          </p>
        </div>
      )}
    </div>
  );
}
