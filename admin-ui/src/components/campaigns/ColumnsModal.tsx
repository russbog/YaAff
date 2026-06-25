import { useEffect, useState } from 'react';
import { ArrowUp, ArrowDown, RotateCcw } from 'lucide-react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { defaultStatFields } from '@/lib/metrics';
import type { StatField } from '@/lib/types';

interface Item {
  field: string;
  title: string;
  desc?: string;
  cat?: string;
  visible: boolean;
}

// Build the working list: visible columns first (in their saved order), then
// the remaining available columns as hidden, preserving the default order.
function buildItems(statFields: StatField[], visibleOrder: string[]): Item[] {
  const byField = new Map(statFields.map((f) => [f.field, f]));
  const items: Item[] = [];
  const seen = new Set<string>();
  for (const field of visibleOrder) {
    const f = byField.get(field);
    if (f && !seen.has(field)) {
      items.push({ field: f.field, title: f.title, desc: f.desc, cat: f.cat, visible: true });
      seen.add(field);
    }
  }
  for (const f of statFields) {
    if (!seen.has(f.field))
      items.push({ field: f.field, title: f.title, desc: f.desc, cat: f.cat, visible: false });
  }
  return items;
}

export function ColumnsModal({
  open,
  onClose,
  statFields,
  value,
  onSave,
  saving,
}: {
  open: boolean;
  onClose: () => void;
  statFields: StatField[];
  value: string[];
  onSave: (columns: string[]) => void;
  saving: boolean;
}) {
  const [items, setItems] = useState<Item[]>(() => buildItems(statFields, value));

  useEffect(() => {
    if (open) setItems(buildItems(statFields, value));
  }, [open, statFields, value]);

  const move = (index: number, dir: -1 | 1) => {
    setItems((prev) => {
      const next = [...prev];
      const target = index + dir;
      if (target < 0 || target >= next.length) return prev;
      [next[index], next[target]] = [next[target], next[index]];
      return next;
    });
  };

  const toggle = (field: string) =>
    setItems((prev) => prev.map((it) => (it.field === field ? { ...it, visible: !it.visible } : it)));

  const setAll = (visible: boolean) => setItems((prev) => prev.map((it) => ({ ...it, visible })));

  const reset = () =>
    setItems(buildItems(statFields, defaultStatFields(statFields).map((f) => f.field)));

  const save = () => {
    const cols = items.filter((it) => it.visible).map((it) => it.field);
    onSave(cols.length ? cols : statFields.map((f) => f.field));
  };

  const visibleCount = items.filter((it) => it.visible).length;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Customize columns"
      description="Show, hide and reorder the campaign stat columns. Your layout is saved for next time."
      size="sm"
      footer={
        <>
          <Button variant="ghost" onClick={reset}>
            <RotateCcw size={14} /> Reset
          </Button>
          <div className="flex-1" />
          <Button variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="primary" onClick={save} loading={saving} disabled={visibleCount === 0}>
            Save
          </Button>
        </>
      }
    >
      <div className="flex items-center justify-between mb-2 text-xs text-muted">
        <span>{visibleCount} of {items.length} shown</span>
        <div className="flex items-center gap-2">
          <button className="hover:text-fg transition-colors" onClick={() => setAll(true)}>
            Show all
          </button>
          <span className="text-faint">·</span>
          <button className="hover:text-fg transition-colors" onClick={() => setAll(false)}>
            Hide all
          </button>
        </div>
      </div>
      <div className="max-h-[50vh] overflow-y-auto rounded-md border border-border divide-y divide-border">
        {items.map((it, i) => (
          <div key={it.field} className="flex items-center gap-2 px-3 py-2 bg-surface">
            <input
              type="checkbox"
              checked={it.visible}
              onChange={() => toggle(it.field)}
              className="h-4 w-4 rounded border-border accent-brand cursor-pointer"
            />
            <span className={`flex-1 text-sm ${it.visible ? 'text-fg' : 'text-faint'}`} title={it.desc}>
              {it.title}
              {it.cat && <span className="ml-2 text-2xs uppercase tracking-wide text-faint">{it.cat}</span>}
            </span>
            <div className="flex items-center gap-0.5">
              <button
                onClick={() => move(i, -1)}
                disabled={i === 0}
                className="grid place-items-center h-6 w-6 rounded text-muted hover:text-fg hover:bg-surface-2 disabled:opacity-30 disabled:hover:bg-transparent transition-colors"
                title="Move up"
              >
                <ArrowUp size={14} />
              </button>
              <button
                onClick={() => move(i, 1)}
                disabled={i === items.length - 1}
                className="grid place-items-center h-6 w-6 rounded text-muted hover:text-fg hover:bg-surface-2 disabled:opacity-30 disabled:hover:bg-transparent transition-colors"
                title="Move down"
              >
                <ArrowDown size={14} />
              </button>
            </div>
          </div>
        ))}
      </div>
    </Modal>
  );
}
