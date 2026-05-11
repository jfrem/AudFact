const locale = process.env.NEXT_PUBLIC_LOCALE ?? "es-CO";
const timeZone = process.env.NEXT_PUBLIC_TIMEZONE ?? "America/Bogota";

export function formatDate(value?: string | number | null) {
  if (!value) {
    return "N/D";
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return String(value);
  }

  return new Intl.DateTimeFormat(locale, {
    dateStyle: "medium",
    timeZone,
  }).format(date);
}

export function formatDateTime(value?: string | number | null) {
  if (!value) {
    return "N/D";
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return String(value);
  }

  return new Intl.DateTimeFormat(locale, {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone,
  }).format(date);
}

export function formatNumber(value?: number | string | null) {
  if (value === null || value === undefined || value === "") {
    return "0";
  }

  return new Intl.NumberFormat(locale).format(Number(value));
}

export function formatDurationMs(value?: number | null) {
  if (!value) {
    return "0 ms";
  }

  if (value < 1000) {
    return `${value} ms`;
  }

  return `${(value / 1000).toFixed(1)} s`;
}
