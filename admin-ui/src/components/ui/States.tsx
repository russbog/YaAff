import type { ReactNode } from 'react';
import { Loader2, Inbox, AlertCircle } from 'lucide-react';
import { cn } from '@/lib/cn';
import { Button } from './Button';

export function Spinner({ className }: { className?: string }) {
  return <Loader2 className={cn('animate-spin text-muted', className)} />;
}

export function Skeleton({ className }: { className?: string }) {
  return <div className={cn('skeleton h-4 w-full', className)} />;
}

export function EmptyState({
  icon,
  title,
  description,
  action,
}: {
  icon?: ReactNode;
  title: string;
  description?: string;
  action?: ReactNode;
}) {
  return (
    <div className="flex flex-col items-center justify-center text-center py-16 px-6">
      <div className="mb-4 grid place-items-center h-14 w-14 rounded-full bg-surface-2 text-faint">
        {icon ?? <Inbox size={26} />}
      </div>
      <h3 className="text-sm font-semibold">{title}</h3>
      {description && <p className="text-xs text-muted mt-1 max-w-sm">{description}</p>}
      {action && <div className="mt-5">{action}</div>}
    </div>
  );
}

export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const message = error instanceof Error ? error.message : 'Something went wrong';
  return (
    <div className="flex flex-col items-center justify-center text-center py-16 px-6">
      <div className="mb-4 grid place-items-center h-14 w-14 rounded-full bg-danger/15 text-danger">
        <AlertCircle size={26} />
      </div>
      <h3 className="text-sm font-semibold">Could not load data</h3>
      <p className="text-xs text-muted mt-1 max-w-sm break-words">{message}</p>
      {onRetry && (
        <Button variant="secondary" size="sm" className="mt-5" onClick={onRetry}>
          Try again
        </Button>
      )}
    </div>
  );
}
