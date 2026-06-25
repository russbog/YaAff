import type { StatField } from '@/lib/types';

/** Canonical order of stat-field categories for the column picker. */
export const METRIC_CATEGORY_ORDER = ['Volume', 'Conversions', 'Rates', 'Cost', 'Earnings'] as const;

const CAT_RANK = new Map<string, number>(METRIC_CATEGORY_ORDER.map((c, i) => [c, i]));

/** True when a stat field should be visible by default in the campaigns grid. */
export function isDefaultStat(f: StatField): boolean {
  return f.default !== false;
}

/** The fields shown by default (used when the user has no saved column layout). */
export function defaultStatFields(statFields: StatField[]): StatField[] {
  return statFields.filter(isDefaultStat);
}

/** Group stat fields by category, preserving the canonical category order. */
export function groupStatFields(statFields: StatField[]): { cat: string; fields: StatField[] }[] {
  const groups = new Map<string, StatField[]>();
  for (const f of statFields) {
    const cat = f.cat ?? 'Other';
    const list = groups.get(cat);
    if (list) list.push(f);
    else groups.set(cat, [f]);
  }
  return [...groups.entries()]
    .sort((a, b) => (CAT_RANK.get(a[0]) ?? 99) - (CAT_RANK.get(b[0]) ?? 99))
    .map(([cat, fields]) => ({ cat, fields }));
}
