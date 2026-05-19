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
  const proxyPath = buildApiUrl(path);

  if (typeof window !== "undefined") {
    return proxyPath;
  }

  const { headers } = await import("next/headers");
  const requestHeaders = await headers();
  const host = requestHeaders.get("x-forwarded-host") ?? requestHeaders.get("host");

  if (host) {
    const protocol = requestHeaders.get("x-forwarded-proto") ?? "http";
    return `${protocol}://${host}${proxyPath}`;
  }

  return `http://127.0.0.1:${process.env.PORT ?? "3000"}${proxyPath}`;
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
