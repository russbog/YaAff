import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Save, Globe, ShieldCheck, GitBranch, Code2, Webhook, KeyRound } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Field';
import { Spinner, ErrorState } from '@/components/ui/States';
import { useToast } from '@/providers/ToastProvider';
import { spa, campaignApi } from '@/lib/api';
import { cn } from '@/lib/cn';
import {
  normalizeCampaign,
  serializeCampaign,
  type Black,
  type CampaignModel,
  type Postback,
  type Scripts,
  type White,
} from '@/lib/campaign';
import { DomainsSection } from './DomainsSection';
import { SafePageSection } from './SafePageSection';
import { FlowsSection } from './FlowsSection';
import { ScriptsSection, PostbacksSection, ApiSection } from './MiscSections';

type SectionId = 'domains' | 'safepage' | 'flows' | 'scripts' | 'postbacks' | 'api';

const SECTIONS: { id: SectionId; label: string; icon: typeof Globe }[] = [
  { id: 'domains', label: 'Domains', icon: Globe },
  { id: 'safepage', label: 'Safe Page', icon: ShieldCheck },
  { id: 'flows', label: 'Flows', icon: GitBranch },
  { id: 'scripts', label: 'Scripts', icon: Code2 },
  { id: 'postbacks', label: 'Postbacks', icon: Webhook },
  { id: 'api', label: 'API', icon: KeyRound },
];

function sanitizeIdentifier(v: string): string {
  return v.trim().replace(/^\/+|\/+$/g, '').replace(/\s+/g, '-').replace(/[^A-Za-z0-9_-]/g, '');
}

function preferredDomain(domains: string[]): string {
  const d = domains.find((x) => x.trim() && !x.includes('*'));
  if (d) return d.replace(/^https?:\/\//, '').split('/')[0];
  return window.location.host;
}

function Editor({ campId, name, initial }: { campId: number; name: string; initial: CampaignModel }) {
  const navigate = useNavigate();
  const toast = useToast();
  const qc = useQueryClient();
  const [model, setModel] = useState<CampaignModel>(initial);
  const [dirty, setDirty] = useState(false);
  const [section, setSection] = useState<SectionId>('domains');

  const patch = (p: Partial<CampaignModel>) => {
    setModel((m) => ({ ...m, ...p }));
    setDirty(true);
  };

  const urlPreview = useMemo(() => {
    const proto = window.location.protocol;
    return `${proto}//${preferredDomain(model.domains)}/${encodeURIComponent(sanitizeIdentifier(model.identifier))}`;
  }, [model.domains, model.identifier]);

  const saveMut = useMutation({
    mutationFn: () => campaignApi.save(campId, serializeCampaign(model)),
    onSuccess: (res) => {
      if (res.error) {
        toast.error(res.result || 'Save failed');
        return;
      }
      toast.success('Settings saved');
      setDirty(false);
      qc.invalidateQueries({ queryKey: ['campaign', campId] });
      qc.invalidateQueries({ queryKey: ['bootstrap'] });
    },
    onError: (e: Error) => toast.error(e.message),
  });

  return (
    <AppShell
      title={name}
      toolbar={
        <div className="flex items-center gap-2">
          <Button variant="ghost" size="sm" onClick={() => navigate('/campaigns')}>
            <ArrowLeft size={14} /> Back
          </Button>
          {dirty && <span className="text-2xs text-amber-500 font-medium">Unsaved changes</span>}
          <Button variant="primary" size="sm" loading={saveMut.isPending} onClick={() => saveMut.mutate()}>
            <Save size={14} /> Save settings
          </Button>
        </div>
      }
    >
      <div className="mb-4 rounded-lg border border-border bg-surface p-4">
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="grid gap-1.5">
            <label className="text-xs font-medium text-muted">Campaign identifier</label>
            <Input
              value={model.identifier}
              placeholder="dsv34g3g"
              onChange={(e) => patch({ identifier: e.target.value })}
            />
          </div>
          <div className="grid gap-1.5">
            <label className="text-xs font-medium text-muted">Public campaign URL</label>
            <Input readOnly value={urlPreview} className="font-mono text-xs" />
          </div>
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-[180px_1fr]">
        <nav className="lg:sticky lg:top-4 self-start">
          <ul className="flex lg:flex-col gap-1 overflow-x-auto">
            {SECTIONS.map((s) => {
              const Icon = s.icon;
              const active = section === s.id;
              return (
                <li key={s.id}>
                  <button
                    type="button"
                    onClick={() => setSection(s.id)}
                    className={cn(
                      'flex items-center gap-2 w-full rounded-md px-3 h-9 text-sm font-medium transition-colors whitespace-nowrap',
                      active ? 'bg-brand/15 text-brand' : 'text-muted hover:text-fg hover:bg-surface-2',
                    )}
                  >
                    <Icon size={15} />
                    {s.label}
                    {s.id === 'flows' && (
                      <span className="ml-auto text-2xs text-faint">{model.black.flows.length}</span>
                    )}
                  </button>
                </li>
              );
            })}
          </ul>
        </nav>

        <div className="min-w-0">
          {section === 'domains' && (
            <DomainsSection domains={model.domains} onChange={(domains) => patch({ domains })} />
          )}
          {section === 'safepage' && (
            <SafePageSection
              white={model.white}
              domains={model.domains}
              onChange={(white: White) => patch({ white })}
            />
          )}
          {section === 'flows' && (
            <FlowsSection
              black={model.black}
              saveuserflow={model.saveuserflow}
              onChangeBlack={(black: Black) => patch({ black })}
              onChangeSaveUserFlow={(saveuserflow) => patch({ saveuserflow })}
            />
          )}
          {section === 'scripts' && (
            <ScriptsSection
              scripts={model.scripts}
              flows={model.black.flows}
              onChange={(scripts: Scripts) => patch({ scripts })}
            />
          )}
          {section === 'postbacks' && (
            <PostbacksSection postback={model.postback} onChange={(postback: Postback) => patch({ postback })} />
          )}
          {section === 'api' && <ApiSection apikey={model.apikey} />}
        </div>
      </div>
    </AppShell>
  );
}

export function CampaignEditor({ campId, name }: { campId: number; name: string }) {
  const query = useQuery({
    queryKey: ['campaign', campId],
    queryFn: () => spa.campaign(campId),
  });

  if (query.isLoading) {
    return (
      <AppShell title={name}>
        <div className="grid place-items-center py-24">
          <Spinner className="h-7 w-7" />
        </div>
      </AppShell>
    );
  }
  if (query.isError || !query.data) {
    return (
      <AppShell title={name}>
        <ErrorState error={query.error} onRetry={() => query.refetch()} />
      </AppShell>
    );
  }

  const initial = normalizeCampaign(query.data.settings);
  // Remount the stateful editor when a fresh payload arrives so local edit
  // state always reflects the loaded campaign.
  return <Editor key={campId} campId={campId} name={name} initial={initial} />;
}
