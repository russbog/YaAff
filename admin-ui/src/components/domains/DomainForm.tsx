import { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { Input, Textarea, Select, FormRow } from '@/components/ui/Field';
import { Segmented } from '@/components/ui/Segmented';
import { spa } from '@/lib/api';
import type { EntityRecord } from '@/lib/types';

export type DomainFieldValues = Record<string, string | boolean>;

function buildInitial(record: EntityRecord | null): DomainFieldValues {
  const s = (record?.settings ?? {}) as Record<string, unknown>;
  const str = (v: unknown, fallback = '') => (typeof v === 'string' && v !== '' ? v : fallback);
  return {
    name: record?.name ?? '',
    group: record?.group ?? '',
    index_allowed: Boolean(s.index_allowed),
    campaign_id: s.campaign_id == null || s.campaign_id === 0 ? '' : String(s.campaign_id),
    intercept_404: Boolean(s.intercept_404),
    type: str(s.type, 'regular'),
    alias_of: str(s.alias_of),
    dns_type: str(s.dns_type, 'A'),
    dns_content: str(s.dns_content),
    dns_proxied: Boolean(s.dns_proxied),
    cf_zone_id: str(s.cf_zone_id),
    cf_api_token: str(s.cf_api_token),
    note: str(s.note),
  };
}

function SectionTitle({ children }: { children: React.ReactNode }) {
  return <h3 className="text-2xs font-semibold uppercase tracking-wide text-faint">{children}</h3>;
}

export function DomainForm({
  record,
  onValuesChange,
}: {
  record: EntityRecord | null;
  onValuesChange: (v: DomainFieldValues) => void;
}) {
  const isNew = !record;
  const [values, setValues] = useState<DomainFieldValues>(() => buildInitial(record));
  const [advancedOpen, setAdvancedOpen] = useState(false);

  useEffect(() => {
    const init = buildInitial(record);
    setValues(init);
    onValuesChange(init);
    setAdvancedOpen(false);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [record]);

  const set = (key: string, value: string | boolean) =>
    setValues((prev) => {
      const next = { ...prev, [key]: value };
      onValuesChange(next);
      return next;
    });

  const { data: campaignsData } = useQuery({
    queryKey: ['campaigns', 'picker'],
    queryFn: () => spa.campaigns(),
    staleTime: 60_000,
  });
  const campaigns = useMemo(
    () => (campaignsData?.rows ?? []).map((r) => ({ id: r.id, name: r.name })),
    [campaignsData],
  );

  const yesNo = [
    { value: '1', label: 'Yes' },
    { value: '0', label: 'No' },
  ];

  return (
    <div className="space-y-5">
      {/* ── Basic ─────────────────────────────────────────── */}
      <div className="space-y-4">
        <SectionTitle>Basic</SectionTitle>

        <FormRow
          label="Domain(s)"
          required
          htmlFor="d-name"
          help={
            isNew
              ? 'One or more hostnames, separated by commas. No http://. Point the domain’s DNS A record to this server’s IP at your registrar and it just works.'
              : 'Hostname for this domain. No scheme (http://).'
          }
        >
          <Input
            id="d-name"
            value={String(values.name)}
            onChange={(e) => set('name', e.target.value)}
            placeholder={isNew ? 'example.com, promo.example.com' : 'example.com'}
            autoFocus
          />
        </FormRow>

        <FormRow label="Group" htmlFor="d-group" help="Optional label to organize domains.">
          <Input id="d-group" value={String(values.group)} onChange={(e) => set('group', e.target.value)} />
        </FormRow>

        <FormRow label="Indexing" help="Allow search engines to crawl this domain. Default is to disallow (recommended for cloaked traffic).">
          <Segmented
            options={[
              { value: '1', label: 'Allow' },
              { value: '0', label: 'Disallow' },
            ]}
            value={values.index_allowed ? '1' : '0'}
            onChange={(v) => set('index_allowed', v === '1')}
          />
        </FormRow>

        <FormRow
          label="Default campaign (index page)"
          htmlFor="d-campaign"
          help="Campaign served at the domain root (example.com/). Links with a campaign identifier (example.com/abc123) keep working as usual. Leave empty for none."
        >
          <Select id="d-campaign" value={String(values.campaign_id)} onChange={(e) => set('campaign_id', e.target.value)}>
            <option value="">— none —</option>
            {campaigns.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name} (#{c.id})
              </option>
            ))}
          </Select>
        </FormRow>

        <FormRow label="Intercept 404" help="When Yes, any unmatched path falls back to the default campaign. When No (or no default campaign is set), a 404 stub is shown.">
          <Segmented
            options={yesNo}
            value={values.intercept_404 ? '1' : '0'}
            onChange={(v) => set('intercept_404', v === '1')}
          />
        </FormRow>
      </div>

      {/* ── Advanced ──────────────────────────────────────── */}
      <div className="border-t border-border pt-3">
        <button
          type="button"
          onClick={() => setAdvancedOpen((o) => !o)}
          className="flex items-center gap-1.5 text-xs font-medium text-muted hover:text-fg"
        >
          {advancedOpen ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
          Advanced (type, aliases, Cloudflare DNS automation)
        </button>

        {advancedOpen && (
          <div className="space-y-4 pt-4">
            <p className="text-2xs text-faint leading-snug">
              These are optional. You only need them for wildcard/alias domains or to auto-create the DNS record
              via the Cloudflare API. If you point DNS manually at your registrar, leave everything here empty.
            </p>

            <FormRow label="Type" htmlFor="d-type" help="Regular host, *.wildcard, or an alias that points to another domain.">
              <Select id="d-type" value={String(values.type)} onChange={(e) => set('type', e.target.value)}>
                <option value="regular">Regular</option>
                <option value="wildcard">Wildcard</option>
                <option value="alias">Alias</option>
              </Select>
            </FormRow>

            {values.type === 'alias' && (
              <FormRow label="Alias of" htmlFor="d-aliasof" help="Canonical host this alias resolves to.">
                <Input id="d-aliasof" value={String(values.alias_of)} onChange={(e) => set('alias_of', e.target.value)} />
              </FormRow>
            )}

            <SectionTitle>Cloudflare DNS automation (optional)</SectionTitle>

            <FormRow label="DNS record type" htmlFor="d-dnstype">
              <Select id="d-dnstype" value={String(values.dns_type)} onChange={(e) => set('dns_type', e.target.value)}>
                {['A', 'CNAME', 'AAAA', 'TXT'].map((t) => (
                  <option key={t} value={t}>
                    {t}
                  </option>
                ))}
              </Select>
            </FormRow>

            <FormRow label="DNS record value" htmlFor="d-dnsvalue" help="IP for A/AAAA, target host for CNAME.">
              <Input id="d-dnsvalue" value={String(values.dns_content)} onChange={(e) => set('dns_content', e.target.value)} />
            </FormRow>

            <label className="flex items-center gap-2.5 cursor-pointer select-none">
              <input
                type="checkbox"
                checked={Boolean(values.dns_proxied)}
                onChange={(e) => set('dns_proxied', e.target.checked)}
                className="h-4 w-4 rounded border-border-strong accent-[rgb(var(--brand))]"
              />
              <span className="text-sm">Cloudflare proxied (orange cloud)</span>
            </label>

            <FormRow label="Cloudflare zone id" htmlFor="d-cfzone" help="Used to create the DNS record via the Cloudflare API.">
              <Input id="d-cfzone" value={String(values.cf_zone_id)} onChange={(e) => set('cf_zone_id', e.target.value)} />
            </FormRow>

            <FormRow label="Cloudflare API token" htmlFor="d-cftoken" help="Scoped token (Zone:DNS:Edit). Stored per domain; never logged.">
              <Input
                id="d-cftoken"
                type="password"
                autoComplete="new-password"
                value={String(values.cf_api_token)}
                onChange={(e) => set('cf_api_token', e.target.value)}
              />
            </FormRow>

            <FormRow label="Note" htmlFor="d-note">
              <Textarea id="d-note" value={String(values.note)} onChange={(e) => set('note', e.target.value)} />
            </FormRow>
          </div>
        )}
      </div>
    </div>
  );
}
