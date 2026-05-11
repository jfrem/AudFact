import type { ClientRecord } from "@/lib/schemas/domain";

/**
 * Extrae NitSec y NitCom de un ClientRecord de forma defensiva.
 * El backend puede retornar campos con nombres variables dependiendo
 * de la vista SQL utilizada.
 */
export function extractClient(record: ClientRecord): {
  nitSec: string;
  nitCom: string;
} {
  const nitSec = String(
    record.NitSec ?? record.nitSec ?? record.nit ?? record.id ?? "",
  );
  const nitCom = String(
    record.NitCom ??
      record.Cliente ??
      record.Nombre ??
      record.RazonSocial ??
      record.razonSocial ??
      `Cliente ${nitSec}`,
  );
  return { nitSec, nitCom };
}
