import { useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Home, RefreshCw, HardDrive, Pencil, Trash2, Plus } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Spinner, EmptyState } from '@/components/ui/States';
import { folderApi, type FolderType } from '@/lib/api';
import type { LoadMode } from '@/lib/campaign';
import { cn } from '@/lib/cn';

// A titled group block matching the legacy "flow-group" visual rhythm.
export function Group({
  title,
  desc,
  children,
  actions,
}: {
  title: string;
  desc?: ReactNode;
  children: ReactNode;
  actions?: ReactNode;
}) {
  return (
    <section className="rounded-lg border border-border bg-surface overflow-hidden">
      <header className="flex items-center justify-between gap-3 px-4 py-2.5 border-b border-border bg-surface-2/40">
        <div className="min-w-0">
          <h4 className="text-xs font-semibold uppercase tracking-wide text-muted">{title}</h4>
          {desc && <p className="text-2xs text-faint mt-0.5">{desc}</p>}
        </div>
        {actions && <div className="flex items-center gap-1.5 shrink-0">{actions}</div>}
      </header>
      <div className="p-4 space-y-3">{children}</div>
    </section>
  );
}

// Small inline radio/segmented for boolean and short enum choices.
export function ChoiceRow<T extends string>({
  label,
  help,
  value,
  options,
  onChange,
}: {
  label: ReactNode;
  help?: ReactNode;
  value: T;
  options: { value: T; label: string }[];
  onChange: (v: T) => void;
}) {
  return (
    <div className="grid gap-2 sm:grid-cols-[200px_1fr] sm:items-start">
      <div className="text-xs font-medium text-muted pt-1.5">
        {label}
        {help && <p className="text-2xs text-faint font-normal mt-0.5">{help}</p>}
      </div>
      <div className="flex flex-wrap gap-1.5">
        {options.map((o) => (
          <button
            key={o.value}
            type="button"
            onClick={() => onChange(o.value)}
            className={cn(
              'rounded-md border px-3 h-8 text-xs font-medium transition-colors',
              o.value === value
                ? 'border-brand bg-brand/15 text-brand'
                : 'border-border bg-surface-2 text-muted hover:text-fg',
            )}
          >
            {o.label}
          </button>
        ))}
      </div>
    </div>
  );
}

const MODE_META: Record<LoadMode, { icon: typeof Home; label: string }> = {
  base: { icon: Home, label: 'Base (rewrite root)' },
  rewrite: { icon: RefreshCw, label: 'Rewrite (proxy assets)' },
  direct: { icon: HardDrive, label: 'Direct (serve as-is)' },
};

// Cycles a load-mode value through the allowed modes for this context.
export function LoadModeToggle({
  mode,
  modes,
  onChange,
}: {
  mode: LoadMode;
  modes: LoadMode[];
  onChange: (m: LoadMode) => void;
}) {
  const cur = modes.includes(mode) ? mode : modes[0];
  const meta = MODE_META[cur];
  const Icon = meta.icon;
  return (
    <button
      type="button"
      title={`Loading mode: ${meta.label} (click to change)`}
      onClick={() => {
        const i = modes.indexOf(cur);
        onChange(modes[(i + 1) % modes.length]);
      }}
      className="inline-flex items-center gap-1.5 h-8 rounded-md border border-border bg-surface-2 px-2.5 text-xs text-muted hover:text-fg"
    >
      <Icon size={14} />
      <span className="hidden sm:inline capitalize">{cur}</span>
    </button>
  );
}

// A read-only folder row with load-mode toggle, edit-files and remove actions.
export function FolderRow({
  name,
  mode,
  modes,
  onModeChange,
  onEdit,
  onRemove,
}: {
  name: string;
  mode?: LoadMode;
  modes?: LoadMode[];
  onModeChange?: (m: LoadMode) => void;
  onEdit?: () => void;
  onRemove: () => void;
}) {
  return (
    <div className="flex items-center gap-2 rounded-md border border-border bg-surface-2/40 px-2.5 py-1.5">
      <span className="flex-1 truncate text-sm font-medium">{name}</span>
      {mode && modes && onModeChange && (
        <LoadModeToggle mode={mode} modes={modes} onChange={onModeChange} />
      )}
      {onEdit && (
        <Button variant="ghost" size="icon" className="h-8 w-8" title="Edit files" onClick={onEdit}>
          <Pencil size={14} />
        </Button>
      )}
      <Button variant="ghost" size="icon" className="h-8 w-8" title="Remove" onClick={onRemove}>
        <Trash2 size={14} className="text-danger" />
      </Button>
    </div>
  );
}

// Add/remove list editor for plain string values (redirect URLs, curls, codes).
export function StringListEditor({
  items,
  onChange,
  placeholder,
  addLabel,
  renderExtra,
}: {
  items: string[];
  onChange: (items: string[]) => void;
  placeholder?: string;
  addLabel: string;
  renderExtra?: (index: number) => ReactNode;
}) {
  return (
    <div className="space-y-2">
      {items.map((v, i) => (
        <div key={i} className="flex items-center gap-2">
          <Input
            className="flex-1"
            value={v}
            placeholder={placeholder}
            onChange={(e) => {
              const next = items.slice();
              next[i] = e.target.value;
              onChange(next);
            }}
          />
          {renderExtra?.(i)}
          <Button
            variant="ghost"
            size="icon"
            className="h-9 w-9 shrink-0"
            title="Remove"
            onClick={() => onChange(items.filter((_, idx) => idx !== i))}
          >
            <Trash2 size={15} className="text-danger" />
          </Button>
        </div>
      ))}
      <Button variant="secondary" size="sm" onClick={() => onChange([...items, ''])}>
        <Plus size={14} /> {addLabel}
      </Button>
    </div>
  );
}

// Picks an existing folder from the server (listfolders.php) to attach.
export function FolderPickerModal({
  open,
  onClose,
  onPick,
  type,
  exclude = [],
}: {
  open: boolean;
  onClose: () => void;
  onPick: (folder: string) => void;
  type: FolderType;
  exclude?: string[];
}) {
  const [q, setQ] = useState('');
  const query = useQuery({
    queryKey: ['folders', type],
    queryFn: () => folderApi.list(type),
    enabled: open,
  });
  const folders = (query.data?.folders ?? []).filter(
    (f) => !exclude.includes(f) && f.toLowerCase().includes(q.toLowerCase()),
  );
  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Add existing folder"
      description={`Pick a ${type === 'white' ? 'safe page' : 'landing'} folder already on the server.`}
    >
      <div className="space-y-3">
        <Input autoFocus placeholder="Search folders…" value={q} onChange={(e) => setQ(e.target.value)} />
        {query.isLoading ? (
          <div className="py-8 grid place-items-center">
            <Spinner className="h-6 w-6" />
          </div>
        ) : folders.length === 0 ? (
          <EmptyState title="No folders" description="Upload a ZIP to create one." />
        ) : (
          <ul className="max-h-72 overflow-y-auto rounded-md border border-border divide-y divide-border">
            {folders.map((f) => (
              <li key={f}>
                <button
                  type="button"
                  className="w-full text-left px-3 py-2 text-sm hover:bg-surface-2 transition-colors"
                  onClick={() => {
                    onPick(f);
                    onClose();
                  }}
                >
                  {f}
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Modal>
  );
}
