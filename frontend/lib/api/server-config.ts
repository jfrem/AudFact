import "server-only";

import { normalizeApiPath } from "@/lib/api/config";

const DEVELOPMENT_INTERNAL_API_URL = "http://127.0.0.1:8080";

export class ApiConfigurationError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "ApiConfigurationError";
  }
}

function normalizeBaseUrl(value: string) {
  return value.trim().replace(/\/$/, "");
}

export function getInternalApiUrl() {
  const configuredUrl = process.env.INTERNAL_API_URL?.trim();

  if (configuredUrl) {
    return normalizeBaseUrl(configuredUrl);
  }

  if (process.env.NODE_ENV !== "production") {
    return DEVELOPMENT_INTERNAL_API_URL;
  }

  throw new ApiConfigurationError(
    "INTERNAL_API_URL debe estar configurada para el frontend en producción.",
  );
}

export function buildInternalApiUrl(path: string) {
  return `${getInternalApiUrl()}${normalizeApiPath(path)}`;
}
