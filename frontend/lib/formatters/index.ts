const timeZone = process.env.NEXT_PUBLIC_TIMEZONE ?? "America/Bogota";

type DateParts = {
  year: string;
  month: string;
  day: string;
  hour: string;
  minute: string;
};

const localDateTimePattern =
  /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::\d{2}(?:\.\d+)?)?)?$/;

export function formatDate(value?: string | number | null) {
  if (!value) {
    return "N/D";
  }

  const parts = getDeterministicDateParts(value);
  if (!parts) {
    return String(value);
  }

  return formatDateParts(parts);
}

export function formatDateTime(value?: string | number | null) {
  if (!value) {
    return "N/D";
  }

  const parts = getDeterministicDateParts(value);
  if (!parts) {
    return String(value);
  }

  return `${formatDateParts(parts)}, ${parts.hour}:${parts.minute}`;
}

export function formatNumber(value?: number | string | null): string {
  if (value === null || value === undefined || value === "") {
    return "0";
  }

  const num = typeof value === "number" ? value : Number(value);
  if (Number.isNaN(num)) {
    return String(value);
  }

  const [intPart, decPart] = String(num).split(".");
  const formattedInt = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
  return decPart !== undefined ? `${formattedInt},${decPart}` : formattedInt;
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

function getDeterministicDateParts(value: string | number): DateParts | null {
  if (typeof value === "string") {
    const match = value.trim().match(localDateTimePattern);
    if (match) {
      return {
        year: match[1],
        month: match[2],
        day: match[3],
        hour: match[4] ?? "00",
        minute: match[5] ?? "00",
      };
    }
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return null;
  }

  const parts = new Intl.DateTimeFormat("en-CA", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
    timeZone,
  }).formatToParts(date);

  const partMap = Object.fromEntries(
    parts.map((part) => [part.type, part.value]),
  );

  return {
    year: partMap.year,
    month: partMap.month,
    day: partMap.day,
    hour: partMap.hour,
    minute: partMap.minute,
  };
}

function formatDateParts(parts: DateParts) {
  return `${Number(parts.day)}/${parts.month}/${parts.year}`;
}
