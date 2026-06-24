import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Download, Upload, Trash2, Database, HardDrive } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Input } from '@/components/ui/Field';
import { Spinner, ErrorState } from '@/components/ui/States';
import { useConfirm } from '@/components/ui/ConfirmDialog';
import { useToast } from '@/providers/ToastProvider';
import { useCan } from '@/providers/BootstrapProvider';
import { dataApi } from '@/lib/api';

function Card({ title, description, children }: { title: string; description?: React.ReactNode; children: React.ReactNode }) {
  return (
    <section className="rounded-lg border border-border bg-surface p-4 shadow-soft space-y-3">
      <div>
        <h4 className="text-sm font-semibold text-fg">{title}</h4>
        {description && <p className="mt-0.5 text-xs text-muted leading-relaxed">{description}</p>}
      </div>
      {children}
    </section>
  );
}

export function DataPage() {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const canManage = useCan()('data.manage');
  const fileRef = useRef<HTMLInputElement>(null);
  const [archive, setArchive] = useState<File | null>(null);
  const [days, setDays] = useState('');

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['data-info'],
    queryFn: () => dataApi.info(),
  });

  const restoreMut = useMutation({
    mutationFn: (f: File) => dataApi.restore(f),
    onSuccess: (r) => {
      const rows = Object.entries(r.restored).map(([k, v]) => `${k}: ${v}`).join(', ');
      toast.success(`Restored — ${rows || 'no rows'}`);
      setArchive(null);
      if (fileRef.current) fileRef.current.value = '';
      qc.invalidateQueries({ queryKey: ['data-info'] });
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const pruneMut = useMutation({
    mutationFn: (d: number) => dataApi.prune(d),
    onSuccess: (r) => {
      const rows = Object.entries(r.deleted).map(([k, v]) => `${k}: ${v}`).join(', ') || 'nothing';
      toast.success(`Pruned rows older than ${r.days} day(s) — ${rows}`);
      qc.invalidateQueries({ queryKey: ['data-info'] });
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const doRestore = async () => {
    if (!archive) return;
    const ok = await confirm({
      title: 'Restore from archive?',
      description: 'This overwrites current row data in any matching tables. This cannot be undone.',
      confirmLabel: 'Restore',
      danger: true,
    });
    if (ok) restoreMut.mutate(archive);
  };

  const doPrune = async () => {
    const n = Number(days) || data?.retentionDays || 0;
    if (n <= 0) {
      toast.error('Set a positive number of days (or configure retentionDays).');
      return;
    }
    const ok = await confirm({
      title: `Prune rows older than ${n} day(s)?`,
      description: 'Matching rows in high-volume tables (clicks, logs) will be permanently deleted.',
      confirmLabel: 'Prune',
      danger: true,
    });
    if (ok) pruneMut.mutate(n);
  };

  return (
    <AppShell title="Data">
      {error ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : (
        <div className="space-y-4 max-w-4xl">
          <div className="grid gap-4 md:grid-cols-2">
            <Card
              title="Backup"
              description="Download a portable JSON archive of every table. Restorable onto either SQLite or MySQL."
            >
              <a href={dataApi.backupUrl()} download>
                <Button variant="primary" size="sm">
                  <Download size={14} /> Download backup
                </Button>
              </a>
            </Card>

            <Card
              title="Restore"
              description={<>Replace row data of existing tables from an archive. <strong className="text-warning">This overwrites current data.</strong></>}
            >
              <input
                ref={fileRef}
                type="file"
                accept=".json,application/json"
                disabled={!canManage}
                onChange={(e) => setArchive(e.target.files?.[0] ?? null)}
                className="block w-full text-sm text-muted file:mr-3 file:rounded-md file:border-0 file:bg-surface-2 file:px-3 file:py-1.5 file:text-fg file:font-medium hover:file:opacity-90 disabled:opacity-50"
              />
              {archive && <p className="text-xs text-muted">{archive.name} · {(archive.size / 1024).toFixed(1)} KB</p>}
              <Button variant="secondary" size="sm" disabled={!archive || !canManage} loading={restoreMut.isPending} onClick={doRestore}>
                <Upload size={14} /> Restore
              </Button>
            </Card>

            <Card
              title="Retention / pruning"
              description={<>Delete old rows from high-volume tables. Configured default: <code className="text-fg">{data?.retentionDays ?? 0}</code> day(s) (<code className="text-fg">retentionDays</code> in settings.php; cron <code className="text-fg">php bin/run_retention.php</code>).</>}
            >
              <div className="flex items-center gap-2 max-w-xs">
                <Input
                  type="number"
                  min={1}
                  placeholder={`days (default ${data?.retentionDays ?? 0})`}
                  value={days}
                  disabled={!canManage}
                  onChange={(e) => setDays(e.target.value)}
                  className="h-8"
                />
                <Button variant="danger" size="sm" disabled={!canManage} loading={pruneMut.isPending} onClick={doPrune}>
                  <Trash2 size={14} /> Prune
                </Button>
              </div>
            </Card>

            <Card
              title="Database"
              description={<span className="inline-flex items-center gap-1"><HardDrive size={13} /> Driver: <code className="text-fg">{isLoading ? '…' : data?.driver ?? '?'}</code></span>}
            >
              {isLoading ? (
                <div className="py-6 grid place-items-center"><Spinner className="h-5 w-5" /></div>
              ) : (
                <div className="rounded-md border border-border overflow-hidden">
                  <table className="w-full text-sm">
                    <thead className="bg-surface-2/40 text-2xs uppercase tracking-wide text-muted">
                      <tr>
                        <th className="px-3 py-2 text-left font-semibold">Table</th>
                        <th className="px-3 py-2 text-right font-semibold">Rows</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(data?.tables ?? []).map((t) => (
                        <tr key={t.name} className="border-t border-border">
                          <td className="px-3 py-1.5">
                            <span className="inline-flex items-center gap-1.5">
                              <Database size={12} className="text-faint" />
                              {t.name}
                              {data?.retentionTables.includes(t.name) && <Badge tone="neutral">retention</Badge>}
                            </span>
                          </td>
                          <td className="px-3 py-1.5 text-right tabular-nums">{t.rows.toLocaleString()}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </Card>
          </div>
        </div>
      )}
    </AppShell>
  );
}
