import {
  ApiConfigurationError,
  buildInternalApiUrl,
} from "@/lib/api/server-config";

export const dynamic = "force-dynamic";
export const runtime = "nodejs";

type RouteContext = {
  params: Promise<{
    path?: string[];
  }>;
};

type ProxyRequestInit = RequestInit & {
  duplex?: "half";
};

const BODYLESS_METHODS = new Set(["GET", "HEAD"]);
const HOP_BY_HOP_HEADERS = [
  "connection",
  "content-encoding",
  "content-length",
  "host",
  "keep-alive",
  "proxy-authenticate",
  "proxy-authorization",
  "te",
  "trailer",
  "transfer-encoding",
  "upgrade",
];

function buildTargetPath(pathSegments: string[] = []) {
  if (pathSegments.length === 0) {
    return "/";
  }

  return `/${pathSegments.map((segment) => encodeURIComponent(segment)).join("/")}`;
}

function copyRequestHeaders(request: Request) {
  const headers = new Headers(request.headers);
  for (const header of HOP_BY_HOP_HEADERS) {
    headers.delete(header);
  }
  headers.delete("accept-encoding");

  const sourceUrl = new URL(request.url);
  headers.set("x-forwarded-host", sourceUrl.host);
  headers.set("x-forwarded-proto", sourceUrl.protocol.replace(":", ""));

  return headers;
}

function copyResponseHeaders(response: Response) {
  const headers = new Headers(response.headers);
  for (const header of HOP_BY_HOP_HEADERS) {
    headers.delete(header);
  }

  return headers;
}

async function proxyBackend(request: Request, context: RouteContext) {
  try {
    const { path } = await context.params;
    const sourceUrl = new URL(request.url);
    const targetUrl = new URL(buildInternalApiUrl(buildTargetPath(path)));
    targetUrl.search = sourceUrl.search;

    const init: ProxyRequestInit = {
      method: request.method,
      headers: copyRequestHeaders(request),
      redirect: "manual",
      cache: "no-store",
    };

    if (!BODYLESS_METHODS.has(request.method)) {
      init.body = request.body;
      init.duplex = "half";
    }

    const response = await fetch(targetUrl, init);
    return new Response(response.body, {
      status: response.status,
      statusText: response.statusText,
      headers: copyResponseHeaders(response),
    });
  } catch (error) {
    if (error instanceof ApiConfigurationError) {
      return Response.json(
        {
          success: false,
          message: "El frontend no tiene configurada la URL interna del backend.",
        },
        { status: 500 },
      );
    }

    return Response.json(
      {
        success: false,
        message: "No se pudo contactar el backend interno.",
      },
      { status: 502 },
    );
  }
}

export function GET(request: Request, context: RouteContext) {
  return proxyBackend(request, context);
}

export function POST(request: Request, context: RouteContext) {
  return proxyBackend(request, context);
}

export function PUT(request: Request, context: RouteContext) {
  return proxyBackend(request, context);
}

export function PATCH(request: Request, context: RouteContext) {
  return proxyBackend(request, context);
}

export function DELETE(request: Request, context: RouteContext) {
  return proxyBackend(request, context);
}

export function HEAD(request: Request, context: RouteContext) {
  return proxyBackend(request, context);
}

export function OPTIONS(request: Request, context: RouteContext) {
  return proxyBackend(request, context);
}
