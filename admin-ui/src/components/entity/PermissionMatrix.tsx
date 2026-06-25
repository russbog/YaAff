import { useMemo } from 'react';
import { X, Plus } from 'lucide-react';
import { useBootstrap } from '@/providers/BootstrapProvider';
import { Input } from '@/components/ui/Field';
import { cn } from '@/lib/cn';

const ACTIONS = [
  { key: 'view', label: 'View' },
  { key: 'manage', label: 'Manage' },
] as const;

/** Mirror of auth/AccessControl::ruleMatches — keep in sync with the backend. */
function ruleMatches(rule: string, needed: string): boolean {
  rule = rule.trim();
  if (rule === '') return false;
  if (rule === '*' || rule === needed) return true;
  const rp = rule.split('.');
  const np = needed.split('.');
  if (rp[rp.length - 1] === '*' && rp.length === np.length + 1) {
    rp.pop();
    return rp.every((seg, i) => seg === np[i]);
  }
  if (rp.length !== np.length) return false;
  return rp.every((seg, i) => seg === '*' || seg === np[i]);
}

function permits(rules: string[], needed: string): boolean {
  return rules.some((r) => ruleMatches(r, needed));
}

function parse(value: string): string[] {
  return value
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean);
}

/** A wildcard rule is anything containing `*` (e.g. `*`, `offers.*`, `*.view`). */
function isWildcard(rule: string): boolean {
  return rule.includes('*');
}

export function PermissionMatrix({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  const { permissionResources } = useBootstrap();
  const resources = permissionResources ?? [];
  const rules = useMemo(() => parse(value), [value]);

  const emit = (next: string[]) => {
    // Stable order, de-duplicated; serialize back to the CSV the backend expects.
    onChange(Array.from(new Set(next)).join(', '));
  };

  const wildcards = rules.filter(isWildcard);
  const hasFull = rules.includes('*');

  const toggleCell = (res: string, action: string) => {
    const tok = `${res}.${action}`;
    if (rules.includes(tok)) emit(rules.filter((r) => r !== tok));
    else emit([...rules, tok]);
  };

  const removeWildcard = (w: string) => emit(rules.filter((r) => r !== w));
  const addFull = () => emit([...rules, '*']);

  if (resources.length === 0) {
    // Fallback: no catalog from the server — edit the raw CSV.
    return (
      <Input
        value={value}
        placeholder="offers.manage, reports.view"
        onChange={(e) => onChange(e.target.value)}
      />
    );
  }

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center gap-1.5">
        {hasFull ? (
          <span className="inline-flex items-center gap-1 rounded-md border border-brand/40 bg-brand/15 px-2 h-7 text-2xs font-medium text-brand">
            Full access (*)
            <button type="button" onClick={() => removeWildcard('*')} aria-label="Remove full access">
              <X size={12} />
            </button>
          </span>
        ) : (
          <button
            type="button"
            onClick={addFull}
            className="inline-flex items-center gap-1 rounded-md border border-border bg-surface-2 px-2 h-7 text-2xs font-medium text-muted hover:border-brand hover:text-brand"
          >
            <Plus size={12} /> Full access
          </button>
        )}
        {wildcards
          .filter((w) => w !== '*')
          .map((w) => (
            <span
              key={w}
              className="inline-flex items-center gap-1 rounded-md border border-border bg-surface-2 px-2 h-7 text-2xs font-mono text-muted"
              title="Wildcard rule"
            >
              {w}
              <button type="button" onClick={() => removeWildcard(w)} aria-label={`Remove ${w}`}>
                <X size={12} />
              </button>
            </span>
          ))}
      </div>

      <div className="overflow-hidden rounded-md border border-border">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border bg-surface-2/50 text-2xs uppercase tracking-wide text-faint">
              <th className="px-3 py-2 text-left font-semibold">Resource</th>
              {ACTIONS.map((a) => (
                <th key={a.key} className="px-3 py-2 text-center font-semibold w-24">
                  {a.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {resources.map((res) => (
              <tr key={res.key} className="border-b border-border/60 last:border-0">
                <td className="px-3 py-1.5 font-medium">{res.label}</td>
                {ACTIONS.map((a) => {
                  const need = `${res.key}.${a.key}`;
                  const explicit = rules.includes(need);
                  const granted = permits(rules, need);
                  const viaWildcard = granted && !explicit;
                  return (
                    <td key={a.key} className="px-3 py-1.5 text-center">
                      <input
                        type="checkbox"
                        checked={granted}
                        disabled={viaWildcard}
                        title={viaWildcard ? 'Granted by a wildcard rule above' : undefined}
                        onChange={() => toggleCell(res.key, a.key)}
                        className={cn(
                          'h-4 w-4 rounded border-border-strong accent-[rgb(var(--brand))]',
                          viaWildcard && 'opacity-60 cursor-not-allowed',
                        )}
                      />
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
