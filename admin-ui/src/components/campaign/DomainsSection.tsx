import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Plus, Trash2, CheckCircle2, AlertTriangle, Asterisk, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Field';
import { domainApi } from '@/lib/api';
import { Group } from './parts';

function DomainStatus({ domain }: { domain: string }) {
  const q = useQuery({
    queryKey: ['domaincheck', domain],
    queryFn: () => domainApi.check(domain),
    enabled: !!domain.trim(),
    staleTime: 60_000,
    retry: false,
  });
  if (!domain.trim()) return null;
  if (q.isLoading) return <Loader2 size={16} className="animate-spin text-faint" />;
  const d = q.data;
  if (!d) return <AlertTriangle size={16} className="text-amber-500" />;
  if (d.wildcard) return <Asterisk size={16} className="text-amber-500" />;
  if (d.cloudflare)
    return (
      <span className="rounded bg-amber-500/15 px-1.5 py-0.5 text-2xs font-bold text-amber-600" title={`Cloudflare (IP ${d.ip ?? '?'})`}>
        CF
      </span>
    );
  if (d.resolves) return <CheckCircle2 size={16} className="text-emerald-500" />;
  return <AlertTriangle size={16} className="text-amber-500" />;
}

export function DomainsSection({
  domains,
  onChange,
}: {
  domains: string[];
  onChange: (d: string[]) => void;
}) {
  const [adding, setAdding] = useState('');

  const add = () => {
    const v = adding.trim().replace(/^https?:\/\//i, '').replace(/\/+$/, '');
    if (!v) return;
    if (domains.some((d) => d.toLowerCase() === v.toLowerCase())) {
      setAdding('');
      return;
    }
    onChange([...domains, v]);
    setAdding('');
  };

  return (
    <Group
      title="Preferred domains"
      desc="Used for convenient link generation and organization. Runtime routing works on any domain pointed at this panel."
    >
      <div className="space-y-2">
        {domains.map((d, i) => (
          <div key={i} className="flex items-center gap-2">
            <Input
              className="flex-1"
              value={d}
              placeholder="domain.com"
              onChange={(e) => {
                const next = domains.slice();
                next[i] = e.target.value;
                onChange(next);
              }}
            />
            <div className="grid w-7 place-items-center shrink-0">
              <DomainStatus domain={d} />
            </div>
            <Button
              variant="ghost"
              size="icon"
              className="h-9 w-9 shrink-0"
              title="Remove"
              onClick={() => onChange(domains.filter((_, idx) => idx !== i))}
            >
              <Trash2 size={15} className="text-danger" />
            </Button>
          </div>
        ))}
      </div>
      <div className="flex items-center gap-2 pt-1">
        <Input
          className="flex-1"
          placeholder="add domain (without http://)"
          value={adding}
          onChange={(e) => setAdding(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              add();
            }
          }}
        />
        <Button variant="secondary" size="sm" onClick={add}>
          <Plus size={14} /> Add domain
        </Button>
      </div>
    </Group>
  );
}
