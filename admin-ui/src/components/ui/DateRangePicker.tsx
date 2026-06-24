import { useState } from 'react';
import { CalendarRange } from 'lucide-react';
import { Segmented } from '@/components/ui/Segmented';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { FormRow, Input } from '@/components/ui/Field';
import { useRange, RANGE_PRESETS } from '@/providers/RangeProvider';

// Unix seconds -> local YYYY-MM-DD for a <input type="date">.
function toDateInput(ts: number): string {
  const d = new Date(ts * 1000);
  const local = new Date(d.getTime() - d.getTimezoneOffset() * 60000);
  return local.toISOString().slice(0, 10);
}

export function DateRangePicker({ size = 'sm' }: { size?: 'sm' | 'md' }) {
  const { preset, setPreset, setCustom, isCustom, bounds } = useRange();
  const [open, setOpen] = useState(false);
  const [from, setFrom] = useState(() => toDateInput(bounds.start));
  const [to, setTo] = useState(() => toDateInput(bounds.end));

  const openModal = () => {
    setFrom(toDateInput(bounds.start));
    setTo(toDateInput(bounds.end));
    setOpen(true);
  };

  const apply = () => {
    const start = Math.floor(new Date(`${from}T00:00:00`).getTime() / 1000);
    const end = Math.floor(new Date(`${to}T23:59:59`).getTime() / 1000);
    if (!Number.isFinite(start) || !Number.isFinite(end) || start >= end) return;
    setCustom({ start, end });
    setOpen(false);
  };

  return (
    <div className="flex items-center gap-2">
      <Segmented
        size={size}
        value={isCustom ? '' : preset.key}
        options={RANGE_PRESETS.map((p) => ({ value: p.key, label: p.label }))}
        onChange={(k) => setPreset(RANGE_PRESETS.find((p) => p.key === k) ?? RANGE_PRESETS[1])}
      />
      <Button variant={isCustom ? 'primary' : 'secondary'} size="sm" onClick={openModal} title="Custom date range">
        <CalendarRange size={14} />
        {isCustom ? `${toDateInput(bounds.start)} → ${toDateInput(bounds.end)}` : 'Custom'}
      </Button>
      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title="Custom date range"
        size="sm"
        footer={
          <>
            <Button variant="ghost" onClick={() => setOpen(false)}>
              Cancel
            </Button>
            <Button variant="primary" onClick={apply} disabled={from >= to}>
              Apply
            </Button>
          </>
        }
      >
        <div className="grid grid-cols-2 gap-3">
          <FormRow label="From">
            <Input type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
          </FormRow>
          <FormRow label="To">
            <Input type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />
          </FormRow>
        </div>
      </Modal>
    </div>
  );
}
