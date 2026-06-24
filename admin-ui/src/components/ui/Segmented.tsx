import { cn } from '@/lib/cn';

export interface SegmentOption<T extends string | number> {
  value: T;
  label: string;
}

export function Segmented<T extends string | number>({
  options,
  value,
  onChange,
  size = 'md',
}: {
  options: SegmentOption<T>[];
  value: T;
  onChange: (v: T) => void;
  size?: 'sm' | 'md';
}) {
  return (
    <div
      role="group"
      className={cn(
        'inline-flex items-center rounded-md bg-surface-2 border border-border p-0.5',
        size === 'sm' ? 'text-xs' : 'text-sm',
      )}
    >
      {options.map((o) => (
        <button
          key={String(o.value)}
          type="button"
          onClick={() => onChange(o.value)}
          className={cn(
            'rounded px-2.5 font-medium transition-colors',
            size === 'sm' ? 'h-6' : 'h-7',
            o.value === value
              ? 'bg-brand text-brand-fg shadow-sm'
              : 'text-muted hover:text-fg',
          )}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}
