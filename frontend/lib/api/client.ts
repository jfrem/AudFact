import { ZodType } from "zod";

import { ApiError } from "@/lib/api/errors";
import { buildApiUrl } from "@/lib/api/config";
import { ApiEnvelopeSchema } from "@/lib/schemas/domain";

type RequestOptions = RequestInit & {
  next?: NextFetchRequestConfig;
};

async function parseJson(response: Response) {
  const text = await response.text();
  if (!text) {
    return null;
  }

  try {
    return JSON.parse(text) as unknown;
  } catch {
    throw new ApiError("La respuesta del backend no es JSON válido.", response.status);
  }
}

async function resolveApiUrl(path: string) {
  if (typeof window !== "undefined") {
    return buildApiUrl(path);
  }

  const internalBase = process.env.INTERNAL_API_URL?.trim();
  if (!internalBase) {
    if (process.env.NODE_ENV === "production") {
      throw new Error("INTERNAL_API_URL debe estar configurada para el frontend en producción.");
    }
    const localBase = "http://127.0.0.1:8080";
    const normalizedPath = path.startsWith("/") ? path : `/${path}`;
    return `${localBase}${normalizedPath}`;
  }

  const normalizedBase = internalBase.replace(/\/$/, "");
  const normalizedPath = path.startsWith("/") ? path : `/${path}`;
  return `${normalizedBase}${normalizedPath}`;
}

export async function requestEnvelope<TSchema extends ZodType>(
  path: string,
  schema: TSchema,
  init?: RequestOptions,
) {
  const response = await fetch(await resolveApiUrl(path), {
    cache: "no-store",
    ...init,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...(init?.headers ?? {}),
    },
  });

  const payload = await parseJson(response);

  if (!response.ok) {
    const message =
      typeof payload === "object" && payload && "message" in payload
        ? String((payload as { message?: string }).message ?? "Error de API")
        : "Error de API";
    throw new ApiError(message, response.status, payload);
  }

  const envelope = ApiEnvelopeSchema(schema).parse(payload);
  return envelope;
}

export async function requestJson<TSchema extends ZodType>(
  path: string,
  schema: TSchema,
  init?: RequestOptions,
) {
  const envelope = await requestEnvelope(path, schema, init);
  return envelope.data;
}

export async function postJson<TSchema extends ZodType, TBody>(
  path: string,
  body: TBody,
  schema: TSchema,
  init?: RequestOptions,
) {
  const envelope = await requestEnvelope(path, schema, {
    method: "POST",
    body: JSON.stringify(body),
    ...init,
  });
  return envelope;
}

export async function requestAttachmentPreview<TSchema extends ZodType>(
  path: string,
  schema: TSchema,
) {
  const response = await fetch(await resolveApiUrl(path), {
    cache: "no-store",
    headers: {
      Accept: "application/json",
    },
  });

  const payload = await parseJson(response);

  if (!response.ok) {
    const message =
      typeof payload === "object" && payload && "message" in payload
        ? String((payload as { message?: string }).message ?? "No se pudo obtener el adjunto.")
        : "No se pudo obtener el adjunto.";
    throw new ApiError(message, response.status, payload);
  }

  return schema.parse(payload);
}
