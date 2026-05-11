import { queryOptions } from "@tanstack/react-query";

import { appConfig } from "@/lib/api/config";
import { getAsyncMetrics, getHealth, getPublicConfig } from "@/lib/api/audfact";
import { queryKeys } from "@/lib/query/query-keys";

export const healthQuery = () =>
  queryOptions({
    queryKey: queryKeys.health,
    queryFn: getHealth,
    refetchInterval: appConfig.pollingHealthMs,
  });

export const publicConfigQuery = () =>
  queryOptions({
    queryKey: queryKeys.publicConfig,
    queryFn: getPublicConfig,
    staleTime: 60_000,
  });

export const asyncMetricsQuery = () =>
  queryOptions({
    queryKey: queryKeys.asyncMetrics,
    queryFn: getAsyncMetrics,
    refetchInterval: appConfig.pollingHealthMs,
  });
