"use client";

import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Search } from "lucide-react";
import { z } from "zod";

import { SectionCard } from "@/components/shared/section-card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

const schema = z.object({
  jobId: z.string().min(32, "Ingresa un jobId válido (mínimo 32 caracteres)."),
});

type FormValues = z.infer<typeof schema>;

export function JobTracker() {
  const router = useRouter();
  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { jobId: "" },
  });

  return (
    <SectionCard
      title="Seguimiento de jobs"
      description="Ingresa el jobId devuelto al encolar una auditoría asíncrona."
    >
      <form
        className="grid gap-4 md:grid-cols-[1fr_auto]"
        onSubmit={form.handleSubmit((values) => {
          router.push(`/audit/jobs/${values.jobId}`);
        })}
      >
        <div className="space-y-2">
          <label htmlFor="jobId" className="text-xs font-medium uppercase tracking-[0.12em] text-slate-400">
            Job ID
          </label>
          <Input
            id="jobId"
            placeholder="Pega aquí el jobId"
            {...form.register("jobId")}
          />
          {form.formState.errors.jobId ? (
            <p className="text-xs text-rose-300">
              {form.formState.errors.jobId.message}
            </p>
          ) : null}
        </div>
        <Button type="submit" className="w-full sm:w-auto h-11 self-end">
          <Search className="h-4 w-4" aria-hidden="true" />
          Abrir tracking
        </Button>
      </form>
    </SectionCard>
  );
}
