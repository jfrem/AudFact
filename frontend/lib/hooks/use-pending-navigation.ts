"use client";

import * as React from "react";
import { useRouter } from "next/navigation";

type NavigationOptions = {
  scroll?: boolean;
};

export function usePendingNavigation() {
  const router = useRouter();
  const [isPending, startTransition] = React.useTransition();

  const push = React.useCallback(
    (href: string, options?: NavigationOptions) => {
      startTransition(() => {
        router.push(href, options);
      });
    },
    [router],
  );

  const replace = React.useCallback(
    (href: string, options?: NavigationOptions) => {
      startTransition(() => {
        router.replace(href, options);
      });
    },
    [router],
  );

  const refresh = React.useCallback(() => {
    startTransition(() => {
      router.refresh();
    });
  }, [router]);

  return {
    isPending,
    push,
    refresh,
    replace,
  };
}
