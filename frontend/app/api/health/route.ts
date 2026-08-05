import { buildInternalApiUrl } from "@/lib/api/server-config";

export const dynamic = "force-dynamic";

export async function GET() {
  let backendOk = false;
  let backendStatus: string | null = null;

  try {
    const res = await fetch(buildInternalApiUrl("/health"), {
      cache: "no-store",
      signal: AbortSignal.timeout(5000),
    });
    backendOk = res.ok;
    if (res.ok) {
      const data = await res.json();
      backendStatus = data?.data?.status ?? null;
    }
  } catch {
    // no-op: fallback to degraded/unreachable
  }

  return Response.json({
    status: backendOk ? "healthy" : "degraded",
    service: "audfact-frontend",
    backend: backendOk ? "reachable" : "unreachable",
    backendStatus,
  });
}
