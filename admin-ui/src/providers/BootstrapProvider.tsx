import { createContext, useContext, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { spa } from '@/lib/api';
import type { BootstrapResponse } from '@/lib/types';
import { Spinner, ErrorState } from '@/components/ui/States';

const Ctx = createContext<BootstrapResponse | null>(null);

export function BootstrapProvider({ children }: { children: ReactNode }) {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['bootstrap'],
    queryFn: spa.bootstrap,
    staleTime: 60_000,
  });

  if (isLoading) {
    return (
      <div className="h-full grid place-items-center bg-bg">
        <div className="flex flex-col items-center gap-3 text-muted">
          <Spinner className="h-7 w-7" />
          <span className="text-sm">Loading YaAff…</span>
        </div>
      </div>
    );
  }

  if (error || !data) {
    return (
      <div className="h-full grid place-items-center bg-bg">
        <ErrorState error={error ?? new Error('No data')} onRetry={() => refetch()} />
      </div>
    );
  }

  return <Ctx.Provider value={data}>{children}</Ctx.Provider>;
}

// eslint-disable-next-line react-refresh/only-export-components
export function useBootstrap(): BootstrapResponse {
  const ctx = useContext(Ctx);
  if (!ctx) throw new Error('useBootstrap must be used within BootstrapProvider');
  return ctx;
}

// eslint-disable-next-line react-refresh/only-export-components
export function useCan(): (perm: string) => boolean {
  const ctx = useContext(Ctx);
  return (perm: string) => ctx?.permissions[perm] ?? true;
}
