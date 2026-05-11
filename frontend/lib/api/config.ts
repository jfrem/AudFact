const PUBLIC_API_URL = (
  process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8080"
).replace(/\/$/, "");
const INTERNAL_API_URL = (
  process.env.INTERNAL_API_URL ??
  process.env.NEXT_PUBLIC_API_URL ??
  "http://127.0.0.1:8080"
).replace(/\/$/, "").replace("localhost", "127.0.0.1");

export const appConfig = {
  apiUrl: PUBLIC_API_URL,
  internalApiUrl: INTERNAL_API_URL,
  appName: process.env.NEXT_PUBLIC_APP_NAME ?? "AudFact",
  defaultTheme: process.env.NEXT_PUBLIC_DEFAULT_THEME ?? "dark",
  pollingJobsMs: Number(process.env.NEXT_PUBLIC_POLLING_JOBS_MS ?? 5000),
  pollingHealthMs: Number(process.env.NEXT_PUBLIC_POLLING_HEALTH_MS ?? 30000),
  locale: process.env.NEXT_PUBLIC_LOCALE ?? "es-CO",
  timeZone: process.env.NEXT_PUBLIC_TIMEZONE ?? "America/Bogota",
};

export function buildApiUrl(path: string) {
  const baseUrl =
    typeof window === "undefined" ? appConfig.internalApiUrl : appConfig.apiUrl;
  return `${baseUrl}${path.startsWith("/") ? path : `/${path}`}`;
}

export function buildPublicApiUrl(path: string) {
  return `${appConfig.apiUrl}${path.startsWith("/") ? path : `/${path}`}`;
}
