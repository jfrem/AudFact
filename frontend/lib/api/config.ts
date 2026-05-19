const BROWSER_API_BASE_PATH = "/api/backend";

export function normalizeApiPath(path: string) {
  return path.startsWith("/") ? path : `/${path}`;
}

export const appConfig = {
  appName: process.env.NEXT_PUBLIC_APP_NAME ?? "AudFact",
  defaultTheme: process.env.NEXT_PUBLIC_DEFAULT_THEME ?? "dark",
  pollingJobsMs: Number(process.env.NEXT_PUBLIC_POLLING_JOBS_MS ?? 5000),
  pollingHealthMs: Number(process.env.NEXT_PUBLIC_POLLING_HEALTH_MS ?? 30000),
  locale: process.env.NEXT_PUBLIC_LOCALE ?? "es-CO",
  timeZone: process.env.NEXT_PUBLIC_TIMEZONE ?? "America/Bogota",
};

export function buildApiUrl(path: string) {
  return `${BROWSER_API_BASE_PATH}${normalizeApiPath(path)}`;
}

export function buildPublicApiUrl(path: string) {
  return buildApiUrl(path);
}
