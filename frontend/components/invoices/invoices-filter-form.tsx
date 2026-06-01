"use client";

import * as React from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";

import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import { Button } from "@/components/ui/button";
import { DatePickerInput } from "@/components/ui/date-picker-input";
import { Field, FieldDescription, FieldLabel } from "@/components/ui/field";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { ClientSelectorCombo } from "@/components/audit/client-selector-combo";
import type { ClientRecord } from "@/lib/schemas/domain";
import {
  INVOICE_SEARCH_DEFAULT_PAGE_SIZE,
  INVOICE_SEARCH_PAGE_SIZE_OPTIONS,
} from "@/lib/api/audfact";
import { usePendingNavigation } from "@/lib/hooks/use-pending-navigation";

const invoicePageSizeSchema = z.preprocess((value) => {
  if (typeof value === "string" && value.trim() === "") {
    return INVOICE_SEARCH_DEFAULT_PAGE_SIZE;
  }

  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : value;
}, z.number({ invalid_type_error: "El tamaño de página debe ser numérico." })
  .int("El tamaño de página debe ser un número entero.")
  .min(1, "El tamaño mínimo es 1.")
  .max(100, "El tamaño máximo es 100."));

const invoiceSearchSchema = z.object({
  facNitSec: z.string().trim().min(1, "El cliente es obligatorio."),
  dateFrom: z.string().min(1, "La fecha inicial es obligatoria."),
  dateTo: z.string().optional(),
  pageSize: invoicePageSizeSchema,
}).superRefine((values, context) => {
  if (values.dateFrom && values.dateTo && values.dateFrom > values.dateTo) {
    context.addIssue({
      code: z.ZodIssueCode.custom,
      path: ["dateTo"],
      message: "La fecha final debe ser mayor o igual a la fecha inicial.",
    });
  }
});

type InvoiceSearchValues = z.infer<typeof invoiceSearchSchema>;

interface InvoicesFilterFormProps {
  allClients: ClientRecord[];
  clientsError?: string | null;
  initialFacNitSec?: string;
  initialDateFrom?: string;
  initialDateTo?: string;
  initialPageSize?: number;
}

export function InvoicesFilterForm({
  allClients,
  clientsError = null,
  initialFacNitSec = "",
  initialDateFrom = "",
  initialDateTo = "",
  initialPageSize = INVOICE_SEARCH_DEFAULT_PAGE_SIZE,
}: InvoicesFilterFormProps) {
  const navigation = usePendingNavigation();
  const form = useForm<InvoiceSearchValues>({
    resolver: zodResolver(invoiceSearchSchema),
    defaultValues: {
      facNitSec: initialFacNitSec,
      dateFrom: initialDateFrom,
      dateTo: initialDateTo,
      pageSize: initialPageSize,
    },
  });

  React.useEffect(() => {
    form.reset({
      facNitSec: initialFacNitSec,
      dateFrom: initialDateFrom,
      dateTo: initialDateTo,
      pageSize: initialPageSize,
    });
  }, [form, initialDateFrom, initialDateTo, initialFacNitSec, initialPageSize]);

  const handleSubmit = (values: InvoiceSearchValues) => {
    const params = new URLSearchParams();

    params.set("page", "1");
    params.set("pageSize", String(values.pageSize));
    params.set("facNitSec", values.facNitSec);
    params.set("dateFrom", values.dateFrom);
    if (values.dateTo) params.set("dateTo", values.dateTo);

    navigation.push(`?${params.toString()}`);
  };

  const clientError = form.formState.errors.facNitSec?.message;
  const dateFromError = form.formState.errors.dateFrom?.message;
  const dateToError = form.formState.errors.dateTo?.message;
  const pageSizeError = form.formState.errors.pageSize?.message;

  return (
    <div className="space-y-4">
      <form
        aria-busy={navigation.isPending}
        className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5"
        noValidate
        onSubmit={form.handleSubmit(handleSubmit)}
      >
        <Field>
          <FieldLabel htmlFor="invoices-client-selector">
            Cliente
          </FieldLabel>
          <ClientSelectorCombo
            id="invoices-client-selector"
            clients={allClients}
            value={form.watch("facNitSec")}
            onValueChange={(value) =>
              form.setValue("facNitSec", value, { shouldDirty: true, shouldValidate: true })
            }
            placeholder="Selecciona un cliente"
            invalid={Boolean(clientError)}
            ariaDescribedBy={clientError ? "invoices-client-error" : undefined}
          />
          {clientError ? (
            <FieldDescription id="invoices-client-error" className="text-rose-300" role="alert">
              {clientError}
            </FieldDescription>
          ) : clientsError ? (
            <FieldDescription className="text-amber-300" role="status">
              {clientsError}
            </FieldDescription>
          ) : null}
        </Field>

        <Field>
          <FieldLabel htmlFor="dateFrom-input">
            Desde
          </FieldLabel>
          <DatePickerInput
            id="dateFrom-input"
            value={form.watch("dateFrom")}
            onValueChange={(value) =>
              form.setValue("dateFrom", value, { shouldDirty: true, shouldValidate: true })
            }
            aria-invalid={Boolean(dateFromError) || undefined}
            aria-describedby={dateFromError ? "invoices-date-from-error" : undefined}
            buttonClassName={dateFromError ? "border-rose-400/70 bg-rose-500/[0.06]" : undefined}
          />
          {dateFromError ? (
            <FieldDescription id="invoices-date-from-error" className="text-rose-300" role="alert">
              {dateFromError}
            </FieldDescription>
          ) : null}
        </Field>

        <Field>
          <FieldLabel htmlFor="dateTo-input">
            Hasta
          </FieldLabel>
          <DatePickerInput
            id="dateTo-input"
            value={form.watch("dateTo")}
            onValueChange={(value) =>
              form.setValue("dateTo", value, { shouldDirty: true, shouldValidate: true })
            }
            aria-invalid={Boolean(dateToError) || undefined}
            aria-describedby={dateToError ? "invoices-date-to-error" : undefined}
            buttonClassName={dateToError ? "border-rose-400/70 bg-rose-500/[0.06]" : undefined}
          />
          {dateToError ? (
            <FieldDescription id="invoices-date-to-error" className="text-rose-300" role="alert">
              {dateToError}
            </FieldDescription>
          ) : null}
        </Field>

        <Field>
          <FieldLabel htmlFor="page-size-select">
            Registros por página
          </FieldLabel>
          <Select
            value={String(form.watch("pageSize"))}
            onValueChange={(value) =>
              form.setValue("pageSize", Number(value), {
                shouldDirty: true,
                shouldValidate: true,
              })
            }
          >
            <SelectTrigger
              id="page-size-select"
              aria-invalid={Boolean(pageSizeError) || undefined}
              aria-describedby={pageSizeError ? "invoices-page-size-error" : undefined}
              className={pageSizeError ? "border-rose-400/70 bg-rose-500/[0.06]" : undefined}
            >
              <SelectValue placeholder="Selecciona" />
            </SelectTrigger>
            <SelectContent>
              {INVOICE_SEARCH_PAGE_SIZE_OPTIONS.map((option) => (
                <SelectItem key={option} value={String(option)}>
                  {option}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {pageSizeError ? (
            <FieldDescription id="invoices-page-size-error" className="text-rose-300" role="alert">
              {pageSizeError}
            </FieldDescription>
          ) : null}
        </Field>

        <div className="flex items-end">
          <Button
            type="submit"
            className="w-full"
            loading={navigation.isPending}
            loadingLabel="Buscando"
          >
            Buscar
          </Button>
        </div>
      </form>

      {navigation.isPending ? (
        <BackendRequestSkeleton
          description="El backend está filtrando facturas con los criterios seleccionados."
          rows={6}
          title="Consultando facturas"
          variant="table"
        />
      ) : null}
    </div>
  );
}
