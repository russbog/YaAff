import { newDomainWhite, type White } from '@/lib/campaign';
import { FilterBuilder } from './FilterBuilder';
import { WhiteConfig, type WhiteConfigValue } from './WhiteConfig';
import { ChoiceRow, Group } from './parts';

export function SafePageSection({
  white,
  domains,
  onChange,
}: {
  white: White;
  domains: string[];
  onChange: (w: White) => void;
}) {
  const globalCfg: WhiteConfigValue = {
    action: white.action,
    folders: white.folders,
    redirect: white.redirect,
    curls: white.curls,
    errorcodes: white.errorcodes,
    loadmode: white.loadmode,
  };

  const setGlobal = (v: WhiteConfigValue) => onChange({ ...white, ...v });

  // Build the per-domain list keyed by the campaign's domains, preserving any
  // existing per-domain config.
  const dwsMap = new Map(white.domainfilter.domains.map((d) => [d.domain, d]));
  const perDomain = domains.map((d) => dwsMap.get(d) ?? newDomainWhite(d));

  const setDomainCfg = (domain: string, v: WhiteConfigValue) => {
    const next = perDomain.map((dw) => (dw.domain === domain ? { ...dw, ...v } : dw));
    onChange({ ...white, domainfilter: { ...white.domainfilter, domains: next } });
  };

  return (
    <div className="space-y-3">
      <Group title="Filters" desc="Traffic matching these filters sees the safe page. Everyone else enters the Flows.">
        <FilterBuilder value={white.filters} onChange={(filters) => onChange({ ...white, filters })} />
      </Group>

      <Group title="Scope">
        <ChoiceRow<'global' | 'domain'>
          label="White page mode"
          value={white.domainfilter.use ? 'domain' : 'global'}
          options={[
            { value: 'global', label: 'Global (same for all domains)' },
            { value: 'domain', label: 'Domain-specific' },
          ]}
          onChange={(mode) =>
            onChange({ ...white, domainfilter: { ...white.domainfilter, use: mode === 'domain' } })
          }
        />
      </Group>

      {!white.domainfilter.use ? (
        <WhiteConfig value={globalCfg} onChange={setGlobal} />
      ) : (
        <div className="space-y-4">
          {perDomain.length === 0 ? (
            <p className="text-2xs text-faint italic px-1">Add a domain first to configure its safe page.</p>
          ) : (
            perDomain.map((dw) => (
              <div key={dw.domain} className="rounded-lg border border-border overflow-hidden">
                <div className="px-4 py-2 bg-surface-2/60 border-b border-border text-sm font-semibold">
                  {dw.domain} — Safe Page
                </div>
                <div className="p-3">
                  <WhiteConfig
                    value={{
                      action: dw.action,
                      folders: dw.folders,
                      redirect: dw.redirect,
                      curls: dw.curls,
                      errorcodes: dw.errorcodes,
                      loadmode: dw.loadmode,
                    }}
                    onChange={(v) => setDomainCfg(dw.domain, v)}
                  />
                </div>
              </div>
            ))
          )}
        </div>
      )}
    </div>
  );
}
