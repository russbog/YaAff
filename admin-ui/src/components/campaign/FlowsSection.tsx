import { useState } from 'react';
import { Plus, Trash2, ChevronDown, ChevronRight, GripVertical } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Field';
import { useConfirm } from '@/components/ui/ConfirmDialog';
import { newFlow, type Black, type Flow, type JsConnect } from '@/lib/campaign';
import { FlowEditor } from './FlowEditor';
import { ChoiceRow, Group } from './parts';

const JBD_EVENTS: { value: string; label: string }[] = [
  { value: 'pointerdown', label: 'Mouse click / Touch' },
  { value: 'keydown', label: 'Text typing' },
  { value: 'devicemotion', label: 'Device motion (Android)' },
  { value: 'deviceorientation', label: 'Device orientation (Android)' },
  { value: 'audiocontext', label: 'Audio engine' },
  { value: 'timezone', label: 'Time zone' },
];

function BlackBehavior({
  black,
  saveuserflow,
  onChangeBlack,
  onChangeSaveUserFlow,
}: {
  black: Black;
  saveuserflow: boolean;
  onChangeBlack: (b: Black) => void;
  onChangeSaveUserFlow: (v: boolean) => void;
}) {
  const jbd = black.jsbotdetection;
  const toggleEvent = (ev: string) => {
    const events = jbd.events.includes(ev) ? jbd.events.filter((e) => e !== ev) : [...jbd.events, ev];
    onChangeBlack({ ...black, jsbotdetection: { ...jbd, events } });
  };

  return (
    <Group title="Black-page behavior">
      <ChoiceRow
        label="Save user flow (sticky)"
        help="Show the same content to a visitor on every revisit."
        value={saveuserflow ? 'true' : 'false'}
        options={[
          { value: 'false', label: 'No' },
          { value: 'true', label: 'Yes' },
        ]}
        onChange={(v) => onChangeSaveUserFlow(v === 'true')}
      />

      <ChoiceRow
        label="JS bot detection"
        help="Show a safe page first; reveal the money page only after browser-side checks confirm a human."
        value={jbd.enabled ? 'true' : 'false'}
        options={[
          { value: 'false', label: 'No' },
          { value: 'true', label: 'Yes' },
        ]}
        onChange={(v) => onChangeBlack({ ...black, jsbotdetection: { ...jbd, enabled: v === 'true' } })}
      />

      {jbd.enabled && (
        <div className="rounded-md border border-border bg-surface-2/40 p-3 space-y-3">
          <div className="grid gap-2 sm:grid-cols-[200px_1fr] sm:items-center">
            <span className="text-xs font-medium text-muted">Timeout (ms)</span>
            <Input
              className="w-32"
              value={jbd.timeout}
              placeholder="10000"
              onChange={(e) => onChangeBlack({ ...black, jsbotdetection: { ...jbd, timeout: e.target.value } })}
            />
          </div>
          <div className="grid gap-2 sm:grid-cols-[200px_1fr] sm:items-start">
            <span className="text-xs font-medium text-muted pt-1">Tests</span>
            <div className="flex flex-wrap gap-1.5">
              {JBD_EVENTS.map((ev) => (
                <button
                  key={ev.value}
                  type="button"
                  onClick={() => toggleEvent(ev.value)}
                  className={`rounded-md border px-2.5 h-8 text-xs font-medium transition-colors ${
                    jbd.events.includes(ev.value)
                      ? 'border-brand bg-brand/15 text-brand'
                      : 'border-border bg-surface-2 text-muted hover:text-fg'
                  }`}
                >
                  {ev.label}
                </button>
              ))}
            </div>
          </div>
          {jbd.events.includes('timezone') && (
            <div className="grid gap-2 sm:grid-cols-[200px_1fr] sm:items-center">
              <span className="text-xs font-medium text-muted">Allowed timezone range</span>
              <div className="flex items-center gap-2">
                <Input
                  className="w-24"
                  placeholder="min -3"
                  value={jbd.timezone.min}
                  onChange={(e) => onChangeBlack({ ...black, jsbotdetection: { ...jbd, timezone: { ...jbd.timezone, min: e.target.value } } })}
                />
                <span className="text-faint">…</span>
                <Input
                  className="w-24"
                  placeholder="max 4"
                  value={jbd.timezone.max}
                  onChange={(e) => onChangeBlack({ ...black, jsbotdetection: { ...jbd, timezone: { ...jbd.timezone, max: e.target.value } } })}
                />
              </div>
            </div>
          )}
        </div>
      )}

      <ChoiceRow<JsConnect>
        label="JavaScript Connect action"
        help="Behavior when a site is connected via <script src='…/js/index.php'>."
        value={black.jsconnect}
        options={[
          { value: 'replace', label: 'Content replace' },
          { value: 'iframe', label: 'IFrame' },
          { value: 'redirect', label: 'Redirect' },
        ]}
        onChange={(jsconnect) => onChangeBlack({ ...black, jsconnect })}
      />
    </Group>
  );
}

export function FlowsSection({
  black,
  saveuserflow,
  onChangeBlack,
  onChangeSaveUserFlow,
}: {
  black: Black;
  saveuserflow: boolean;
  onChangeBlack: (b: Black) => void;
  onChangeSaveUserFlow: (v: boolean) => void;
}) {
  const confirm = useConfirm();
  const [openIdx, setOpenIdx] = useState<number | null>(0);
  const flows = black.flows;

  const setFlows = (next: Flow[]) => onChangeBlack({ ...black, flows: next });
  const updateFlow = (i: number, f: Flow) => {
    const next = flows.slice();
    next[i] = f;
    setFlows(next);
  };
  const move = (i: number, dir: -1 | 1) => {
    const j = i + dir;
    if (j < 0 || j >= flows.length) return;
    const next = flows.slice();
    [next[i], next[j]] = [next[j], next[i]];
    setFlows(next);
    setOpenIdx(j);
  };
  const addFlow = () => {
    setFlows([...flows, newFlow(`Flow ${flows.length + 1}`)]);
    setOpenIdx(flows.length);
  };
  const removeFlow = async (i: number) => {
    const ok = await confirm({
      title: 'Delete flow?',
      description: `“${flows[i].name}” and its steps will be removed.`,
      confirmLabel: 'Delete',
      danger: true,
    });
    if (!ok) return;
    setFlows(flows.filter((_, idx) => idx !== i));
    setOpenIdx(null);
  };

  return (
    <div className="space-y-3">
      <BlackBehavior
        black={black}
        saveuserflow={saveuserflow}
        onChangeBlack={onChangeBlack}
        onChangeSaveUserFlow={onChangeSaveUserFlow}
      />

      <Group
        title="Flows"
        desc="Processed top-to-bottom. The first flow whose filters match the visitor gets the traffic."
        actions={
          <Button variant="primary" size="sm" onClick={addFlow}>
            <Plus size={14} /> Add flow
          </Button>
        }
      >
        {flows.length === 0 ? (
          <p className="text-2xs text-faint italic">No flows. Add one to route money-page traffic.</p>
        ) : (
          <div className="space-y-2">
            {flows.map((f, i) => {
              const open = openIdx === i;
              return (
                <div key={i} className="rounded-lg border border-border overflow-hidden">
                  <div className="flex items-center gap-2 px-2.5 py-2 bg-surface-2/50">
                    <GripVertical size={15} className="text-faint shrink-0" />
                    <button
                      type="button"
                      className="shrink-0 text-muted hover:text-fg"
                      onClick={() => setOpenIdx(open ? null : i)}
                      title={open ? 'Collapse' : 'Expand'}
                    >
                      {open ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
                    </button>
                    <Input
                      className="h-8 w-56"
                      value={f.name}
                      onChange={(e) => updateFlow(i, { ...f, name: e.target.value })}
                    />
                    <span className="text-2xs text-faint hidden sm:inline">
                      {f.steps.length} step{f.steps.length === 1 ? '' : 's'} · {f.distribution}
                    </span>
                    <div className="ml-auto flex items-center gap-1">
                      <Button variant="ghost" size="icon" className="h-7 w-7" title="Move up" onClick={() => move(i, -1)} disabled={i === 0}>
                        ↑
                      </Button>
                      <Button variant="ghost" size="icon" className="h-7 w-7" title="Move down" onClick={() => move(i, 1)} disabled={i === flows.length - 1}>
                        ↓
                      </Button>
                      <Button variant="ghost" size="icon" className="h-7 w-7" title="Delete flow" onClick={() => removeFlow(i)}>
                        <Trash2 size={14} className="text-danger" />
                      </Button>
                    </div>
                  </div>
                  {open && (
                    <div className="p-3 border-t border-border">
                      <FlowEditor flow={f} onChange={(nf) => updateFlow(i, nf)} />
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </Group>
    </div>
  );
}
