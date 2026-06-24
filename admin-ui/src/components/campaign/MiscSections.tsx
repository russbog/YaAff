import { useQuery } from '@tanstack/react-query';
import { Plus, Trash2, ArrowUp, ArrowDown, Copy } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input, Select } from '@/components/ui/Field';
import { useToast } from '@/providers/ToastProvider';
import { entityApi, API_BASE } from '@/lib/api';
import type { Flow, Postback, Scripts, ScriptRule, S2sPostback, DedupKey } from '@/lib/campaign';
import { ChoiceRow, Group, StringListEditor } from './parts';

const S2S_STATUSES = ['Lead', 'Purchase', 'Reject', 'Trash'];

function cloakerRoot(): string {
  const apiRoot = API_BASE.replace(/\/$/, '');
  return apiRoot.replace(/\/admin$/, '');
}

function CopyField({ value }: { value: string }) {
  const toast = useToast();
  return (
    <div className="flex items-center gap-2">
      <Input readOnly value={value} className="flex-1 font-mono text-xs" />
      <Button
        variant="ghost"
        size="icon"
        className="h-9 w-9 shrink-0"
        title="Copy"
        onClick={() => {
          navigator.clipboard?.writeText(value);
          toast.success('Copied');
        }}
      >
        <Copy size={15} />
      </Button>
    </div>
  );
}

// --- Scripts --------------------------------------------------------------

function StepChips({
  flow,
  flows,
  steps,
  onChange,
}: {
  flow: string;
  flows: Flow[];
  steps: '*' | number[];
  onChange: (s: '*' | number[]) => void;
}) {
  const count =
    flow === '*'
      ? Math.max(0, ...flows.map((f) => f.steps.length))
      : flows.find((f) => f.name === flow)?.steps.length ?? 0;
  if (count <= 0) return <span className="text-2xs text-faint italic">Any step</span>;

  const toggle = (n: number) => {
    if (steps === '*') {
      onChange([n]);
      return;
    }
    const has = steps.includes(n);
    const next = has ? steps.filter((s) => s !== n) : [...steps, n].sort((a, b) => a - b);
    onChange(next.length === 0 ? '*' : next);
  };

  return (
    <div className="flex flex-wrap gap-1">
      <button
        type="button"
        onClick={() => onChange('*')}
        className={`rounded border px-2 h-7 text-2xs font-medium ${steps === '*' ? 'border-brand bg-brand/15 text-brand' : 'border-border bg-surface-2 text-muted'}`}
      >
        Any
      </button>
      {Array.from({ length: count }, (_, i) => (
        <button
          key={i}
          type="button"
          onClick={() => toggle(i)}
          className={`rounded border px-2 h-7 text-2xs font-medium ${steps !== '*' && steps.includes(i) ? 'border-brand bg-brand/15 text-brand' : 'border-border bg-surface-2 text-muted'}`}
        >
          Step {i + 1}
        </button>
      ))}
    </div>
  );
}

function RuleList({
  kind,
  rules,
  flows,
  onChange,
}: {
  kind: 'next' | 'submit';
  rules: ScriptRule[];
  flows: Flow[];
  onChange: (r: ScriptRule[]) => void;
}) {
  const update = (i: number, r: ScriptRule) => {
    const next = rules.slice();
    next[i] = r;
    onChange(next);
  };
  const move = (i: number, dir: -1 | 1) => {
    const j = i + dir;
    if (j < 0 || j >= rules.length) return;
    const next = rules.slice();
    [next[i], next[j]] = [next[j], next[i]];
    onChange(next);
  };
  return (
    <div className="space-y-2">
      {rules.map((r, i) => (
        <div key={i} className="rounded-md border border-border bg-surface-2/40 p-2.5 space-y-2">
          <div className="flex flex-wrap items-center gap-2">
            <Select
              className="h-8 w-44 text-xs"
              value={r.flow}
              onChange={(e) => update(i, { ...r, flow: e.target.value })}
            >
              <option value="*">Any flow</option>
              {flows.map((f) => (
                <option key={f.name} value={f.name}>
                  {f.name}
                </option>
              ))}
            </Select>
            {kind === 'next' && (
              <StepChips flow={r.flow} flows={flows} steps={r.steps} onChange={(steps) => update(i, { ...r, steps })} />
            )}
            <div className="ml-auto flex items-center gap-1">
              <Button variant="ghost" size="icon" className="h-7 w-7" title="Move up" onClick={() => move(i, -1)} disabled={i === 0}>
                <ArrowUp size={13} />
              </Button>
              <Button variant="ghost" size="icon" className="h-7 w-7" title="Move down" onClick={() => move(i, 1)} disabled={i === rules.length - 1}>
                <ArrowDown size={13} />
              </Button>
              <Button variant="ghost" size="icon" className="h-7 w-7" title="Remove" onClick={() => onChange(rules.filter((_, idx) => idx !== i))}>
                <Trash2 size={14} className="text-danger" />
              </Button>
            </div>
          </div>
          <Input
            placeholder="https://example.com/path?clickid={clickid}"
            value={r.url}
            onChange={(e) => update(i, { ...r, url: e.target.value })}
          />
        </div>
      ))}
      <Button
        variant="secondary"
        size="sm"
        onClick={() => onChange([...rules, { flow: '*', steps: '*', url: '' }])}
      >
        <Plus size={14} /> Add rule
      </Button>
    </div>
  );
}

export function ScriptsSection({
  scripts,
  flows,
  onChange,
}: {
  scripts: Scripts;
  flows: Flow[];
  onChange: (s: Scripts) => void;
}) {
  return (
    <div className="space-y-3">
      <Group title="Backfix" desc="Prevents back navigation; shows another money page instead.">
        <ChoiceRow
          label="Use backfix"
          value={scripts.backfix.use ? 'true' : 'false'}
          options={[
            { value: 'false', label: 'No' },
            { value: 'true', label: 'Yes' },
          ]}
          onChange={(v) => onChange({ ...scripts, backfix: { ...scripts.backfix, use: v === 'true' } })}
        />
        {scripts.backfix.use && (
          <StringListEditor
            items={scripts.backfix.urls}
            onChange={(urls) => onChange({ ...scripts, backfix: { ...scripts.backfix, urls } })}
            placeholder="http://ya.ru?pixel={px}&clickid={clickid}"
            addLabel="Add backfix URL"
          />
        )}
      </Group>

      <Group title="Next step redirect" desc="On a matched flow/step, the next step opens in a new tab and the current tab redirects.">
        <ChoiceRow
          label="Enable"
          value={scripts.nextredirect.use ? 'true' : 'false'}
          options={[
            { value: 'false', label: 'No' },
            { value: 'true', label: 'Yes' },
          ]}
          onChange={(v) => onChange({ ...scripts, nextredirect: { ...scripts.nextredirect, use: v === 'true' } })}
        />
        {scripts.nextredirect.use && (
          <RuleList
            kind="next"
            rules={scripts.nextredirect.rules}
            flows={flows}
            onChange={(rules) => onChange({ ...scripts, nextredirect: { ...scripts.nextredirect, rules } })}
          />
        )}
      </Group>

      <Group title="Form submit redirect" desc="On a matched flow, form submit opens in a new tab and the current tab redirects.">
        <ChoiceRow
          label="Enable"
          value={scripts.submitredirect.use ? 'true' : 'false'}
          options={[
            { value: 'false', label: 'No' },
            { value: 'true', label: 'Yes' },
          ]}
          onChange={(v) => onChange({ ...scripts, submitredirect: { ...scripts.submitredirect, use: v === 'true' } })}
        />
        {scripts.submitredirect.use && (
          <RuleList
            kind="submit"
            rules={scripts.submitredirect.rules}
            flows={flows}
            onChange={(rules) => onChange({ ...scripts, submitredirect: { ...scripts.submitredirect, rules } })}
          />
        )}
      </Group>

      <Group title="Event tracking">
        <ChoiceRow
          label="Track scroll depth"
          value={scripts.events.scroll.use ? 'true' : 'false'}
          options={[
            { value: 'false', label: 'No' },
            { value: 'true', label: 'Yes' },
          ]}
          onChange={(v) => onChange({ ...scripts, events: { ...scripts.events, scroll: { ...scripts.events.scroll, use: v === 'true' } } })}
        />
        {scripts.events.scroll.use && (
          <div className="grid gap-2 sm:grid-cols-[200px_1fr] sm:items-center">
            <span className="text-xs font-medium text-muted">Scroll thresholds, %</span>
            <Input
              className="max-w-xs"
              placeholder="50,75,90"
              value={scripts.events.scroll.thresholds.join(',')}
              onChange={(e) =>
                onChange({
                  ...scripts,
                  events: { ...scripts.events, scroll: { ...scripts.events.scroll, thresholds: parseThresholds(e.target.value) } },
                })
              }
            />
          </div>
        )}
        <ChoiceRow
          label="Track visible time"
          value={scripts.events.time.use ? 'true' : 'false'}
          options={[
            { value: 'false', label: 'No' },
            { value: 'true', label: 'Yes' },
          ]}
          onChange={(v) => onChange({ ...scripts, events: { ...scripts.events, time: { ...scripts.events.time, use: v === 'true' } } })}
        />
        {scripts.events.time.use && (
          <div className="grid gap-2 sm:grid-cols-[200px_1fr] sm:items-center">
            <span className="text-xs font-medium text-muted">Time thresholds, seconds</span>
            <Input
              className="max-w-xs"
              placeholder="30,60,120"
              value={scripts.events.time.thresholds.join(',')}
              onChange={(e) =>
                onChange({
                  ...scripts,
                  events: { ...scripts.events, time: { ...scripts.events.time, thresholds: parseThresholds(e.target.value) } },
                })
              }
            />
          </div>
        )}
      </Group>

      <Group title="Performance">
        <ChoiceRow
          label="Lazy-load images"
          value={scripts.imageslazyload ? 'true' : 'false'}
          options={[
            { value: 'false', label: 'No' },
            { value: 'true', label: 'Yes' },
          ]}
          onChange={(v) => onChange({ ...scripts, imageslazyload: v === 'true' })}
        />
      </Group>
    </div>
  );
}

function parseThresholds(v: string): number[] {
  return v
    .split(',')
    .map((s) => parseInt(s.trim(), 10))
    .filter((n) => Number.isFinite(n) && n > 0);
}

// --- Postbacks ------------------------------------------------------------

export function PostbacksSection({ postback, onChange }: { postback: Postback; onChange: (p: Postback) => void }) {
  const root = cloakerRoot();
  const integrationsQuery = useQuery({
    queryKey: ['entity', 'integrations'],
    queryFn: () => entityApi.list('integrations'),
  });
  const integrations = integrationsQuery.data?.items ?? [];

  const setEvent = (k: keyof Postback['events'], v: string) =>
    onChange({ ...postback, events: { ...postback.events, [k]: v } });

  const updateS2s = (i: number, s: S2sPostback) => {
    const next = postback.s2s.slice();
    next[i] = s;
    onChange({ ...postback, s2s: next });
  };

  return (
    <div className="space-y-3">
      <Group title="Postback URL" desc="Put this into your affiliate network's postback settings.">
        <CopyField value={`${root}/api/postback.php?clickid={sub1}&payout={payout}&currency=USD&status={status}`} />
        <div className="grid gap-3 sm:grid-cols-2 pt-1">
          {(['lead', 'purchase', 'reject', 'trash'] as const).map((k) => (
            <div key={k} className="grid gap-1.5">
              <span className="text-xs font-medium text-muted capitalize">{k} status name</span>
              <Input value={postback.events[k]} placeholder={k} onChange={(e) => setEvent(k, e.target.value)} />
            </div>
          ))}
        </div>
      </Group>

      <Group
        title="S2S postbacks"
        actions={
          <Button
            variant="secondary"
            size="sm"
            onClick={() => onChange({ ...postback, s2s: [...postback.s2s, { url: '', method: 'GET', events: [] }] })}
          >
            <Plus size={14} /> Add
          </Button>
        }
      >
        {postback.s2s.length === 0 ? (
          <p className="text-2xs text-faint italic">No server-to-server postbacks.</p>
        ) : (
          <div className="space-y-3">
            {postback.s2s.map((s, i) => (
              <div key={i} className="rounded-md border border-border bg-surface-2/40 p-3 space-y-2">
                <div className="flex items-center gap-2">
                  <Input
                    className="flex-1"
                    placeholder="https://s2s-postback.com?clickid={clickid}"
                    value={s.url}
                    onChange={(e) => updateS2s(i, { ...s, url: e.target.value })}
                  />
                  <Select
                    className="w-24 shrink-0"
                    value={s.method}
                    onChange={(e) => updateS2s(i, { ...s, method: e.target.value as 'GET' | 'POST' })}
                  >
                    <option value="GET">GET</option>
                    <option value="POST">POST</option>
                  </Select>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="h-9 w-9 shrink-0"
                    title="Remove"
                    onClick={() => onChange({ ...postback, s2s: postback.s2s.filter((_, idx) => idx !== i) })}
                  >
                    <Trash2 size={15} className="text-danger" />
                  </Button>
                </div>
                <div className="flex flex-wrap items-center gap-1.5">
                  <span className="text-2xs text-faint mr-1">Fire on:</span>
                  {S2S_STATUSES.map((st) => {
                    const on = s.events.includes(st);
                    return (
                      <button
                        key={st}
                        type="button"
                        onClick={() =>
                          updateS2s(i, { ...s, events: on ? s.events.filter((e) => e !== st) : [...s.events, st] })
                        }
                        className={`rounded border px-2 h-7 text-2xs font-medium ${on ? 'border-brand bg-brand/15 text-brand' : 'border-border bg-surface-2 text-muted'}`}
                      >
                        {st}
                      </button>
                    );
                  })}
                </div>
              </div>
            ))}
          </div>
        )}
      </Group>

      <Group title="Conversion settings">
        <ChoiceRow<DedupKey>
          label="Dedup key"
          help="Prevents duplicate conversions per campaign."
          value={postback.dedup_key}
          options={[
            { value: 'clickid_tid', label: 'Click ID + Transaction ID' },
            { value: 'tid', label: 'Transaction ID only' },
            { value: 'clickid', label: 'Click ID only' },
          ]}
          onChange={(dedup_key) => onChange({ ...postback, dedup_key })}
        />
        <div className="grid gap-2 sm:grid-cols-[200px_1fr] sm:items-start">
          <span className="text-xs font-medium text-muted pt-1">Conversion API integrations</span>
          {integrations.length === 0 ? (
            <p className="text-2xs text-faint italic pt-1">No integrations configured.</p>
          ) : (
            <div className="flex flex-wrap gap-1.5">
              {integrations.map((integ) => {
                const on = postback.integrations.includes(integ.id);
                return (
                  <button
                    key={integ.id}
                    type="button"
                    onClick={() =>
                      onChange({
                        ...postback,
                        integrations: on
                          ? postback.integrations.filter((x) => x !== integ.id)
                          : [...postback.integrations, integ.id],
                      })
                    }
                    className={`rounded-md border px-2.5 h-8 text-xs font-medium ${on ? 'border-brand bg-brand/15 text-brand' : 'border-border bg-surface-2 text-muted hover:text-fg'}`}
                  >
                    {integ.name || `Integration #${integ.id}`}
                  </button>
                );
              })}
            </div>
          )}
        </div>
        <div className="grid gap-1.5 pt-1">
          <span className="text-xs font-medium text-muted">Conversion pixel URL</span>
          <CopyField value={`${root}/api/pixel.php?clickid={clickid}&status=lead&payout=0`} />
        </div>
      </Group>
    </div>
  );
}

// --- API ------------------------------------------------------------------

export function ApiSection({ apikey }: { apikey: string }) {
  const root = cloakerRoot();
  return (
    <Group title="REST API" desc="API methods are described in the docs.">
      <div className="grid gap-1.5">
        <span className="text-xs font-medium text-muted">This campaign's API URL</span>
        <CopyField value={`${root}/api/phpconnect.php?apikey=${apikey}`} />
      </div>
    </Group>
  );
}
