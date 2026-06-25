import type { ReportNode } from '@/lib/types';
import { toNumber } from '@/lib/format';

// Additive base metrics that can be summed directly across sibling groups.
const BASE_FIELDS = [
  'clicks',
  'uniques',
  'conversion',
  'purchase',
  'hold',
  'reject',
  'trash',
  'revenue',
  'costs',
  'profit',
] as const;

function div(a: number, b: number): number {
  return b === 0 ? 0 : a / b;
}

/**
 * Recompute every derived rate metric from the additive base totals, mirroring
 * the legacy Tabulator bottomCalc formulas. Used to build a correct grand-total
 * row (rates cannot simply be summed).
 */
export function deriveRates(base: Record<string, number>): Record<string, number> {
  const clicks = base.clicks ?? 0;
  const uniques = base.uniques ?? 0;
  const conv = base.conversion ?? 0;
  const purchase = base.purchase ?? 0;
  const trash = base.trash ?? 0;
  const revenue = base.revenue ?? 0;
  const costs = base.costs ?? 0;
  const profit = base.profit ?? revenue - costs;
  return {
    uniques_ratio: div(uniques, clicks) * 100,
    cra: div(conv, clicks) * 100,
    crs: div(purchase, clicks) * 100,
    app: div(purchase, conv) * 100,
    appt: div(purchase, conv - trash) * 100,
    cpc: div(costs, clicks),
    ucpc: div(costs, uniques),
    cpa: div(costs, conv),
    epc: div(revenue, clicks),
    uepc: div(revenue, uniques),
    ec: div(revenue, conv),
    roi: div(profit, costs) * 100,
  };
}

/**
 * Grand totals for a report tree: sum additive base metrics across the
 * top-level group nodes (which partition all rows), then derive rate metrics.
 */
export function computeReportTotals(nodes: ReportNode[]): Record<string, number> {
  const base: Record<string, number> = {};
  for (const f of BASE_FIELDS) base[f] = 0;
  for (const node of nodes) {
    for (const f of BASE_FIELDS) base[f] += toNumber(node[f]);
  }
  return { ...base, ...deriveRates(base) };
}
