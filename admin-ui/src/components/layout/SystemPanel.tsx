import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Settings2, CloudDownload, Database, Clock, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Select } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { useToast } from '@/providers/ToastProvider';
import { useConfirm } from '@/components/ui/ConfirmDialog';
import { useBootstrap } from '@/providers/BootstrapProvider';
import { systemApi, APP_VERSION } from '@/lib/api';
import type { TimezoneOption } from '@/lib/types';

function tzOptions(
  timezones: TimezoneOption[] | Record<string, string>,
): TimezoneOption[] {
  if (Array.isArray(timezones)) return timezones;
  return Object.entries(timezones).map(([value, label]) => ({ value, label }));
}

export function SystemPanel() {
  const [open, setOpen] = useState(false);
  const { geoBases, timezones, commonSettings, version } = useBootstrap();
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();

  const currentTz = commonSettings.statistics?.timezone ?? 'UTC';
  const [tz, setTz] = useState(currentTz);

  const checkMut = useMutation({
    mutationFn: systemApi.checkUpdate,
    onSuccess: async (r) => {
      if (!r.hasUpdate) {
        toast.info('YaAff is up to date');
        return;
      }
      const ok = await confirm({
        title: `Update to v${r.version}?`,
        description:
          'YaAff will download the new version from GitHub and replace its files. This cannot be undone automatically.',
        confirmLabel: 'Update now',
      });
      if (ok) applyMut.mutate();
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const applyMut = useMutation({
    mutationFn: systemApi.applyUpdate,
    onSuccess: (msg) => {
      toast.success(msg || 'Update complete — reloading');
      setTimeout(() => window.location.reload(), 1200);
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const geoMut = useMutation({
    mutationFn: systemApi.updateGeobases,
    onSuccess: (msg) => {
      toast.success(msg.trim() || 'GeoBases updated');
      qc.invalidateQueries({ queryKey: ['bootstrap'] });
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const tzMut = useMutation({
    mutationFn: (value: string) => systemApi.saveTimezone(value),
    onSuccess: () => {
      toast.success('Timezone saved');
      qc.invalidateQueries();
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const busy = checkMut.isPending || applyMut.isPending;
  const geoMissing = (geoBases?.missing.length ?? 0) > 0;

  return (
    <>
      <Button
        variant="ghost"
        size="icon"
        onClick={() => setOpen(true)}
        title="System & updates"
        aria-label="System and updates"
      >
        <Settings2 size={16} />
      </Button>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title="System & updates"
        description="Application updates, GeoIP databases, and reporting timezone."
      >
        <div className="space-y-5">
          <section className="space-y-2">
            <div className="flex items-center gap-2 text-sm font-medium">
              <CloudDownload size={16} className="text-brand" />
              Application
            </div>
            <div className="flex items-center justify-between gap-3 rounded-md border border-border bg-surface-2/40 px-3 py-2.5">
              <div className="text-xs text-muted">
                Current version <Badge tone="neutral">v{version || APP_VERSION}</Badge>
              </div>
              <Button size="sm" onClick={() => checkMut.mutate()} loading={busy}>
                {applyMut.isPending ? 'Updating…' : 'Check for updates'}
              </Button>
            </div>
          </section>

          <section className="space-y-2">
            <div className="flex items-center gap-2 text-sm font-medium">
              <Database size={16} className="text-info" />
              GeoIP databases
            </div>
            <div className="flex items-center justify-between gap-3 rounded-md border border-border bg-surface-2/40 px-3 py-2.5">
              <div className="text-xs text-muted min-w-0">
                {geoMissing ? (
                  <Badge tone="danger">Missing: {geoBases?.missing.join(', ')}</Badge>
                ) : (
                  <span>
                    Version <Badge tone="success">{geoBases?.version || 'Found'}</Badge>
                  </span>
                )}
                {geoBases?.source && (
                  <div className="text-2xs text-faint mt-1 truncate">{geoBases.source}</div>
                )}
              </div>
              <Button
                size="sm"
                variant="secondary"
                onClick={() => geoMut.mutate()}
                loading={geoMut.isPending}
              >
                <RefreshCw size={14} />
                Update
              </Button>
            </div>
          </section>

          <section className="space-y-2">
            <div className="flex items-center gap-2 text-sm font-medium">
              <Clock size={16} className="text-success" />
              Reporting timezone
            </div>
            <div className="flex items-center gap-2">
              <Select value={tz} onChange={(e) => setTz(e.target.value)} className="text-xs">
                {tzOptions(timezones).map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
              <Button
                size="sm"
                onClick={() => tzMut.mutate(tz)}
                loading={tzMut.isPending}
                disabled={tz === currentTz}
              >
                Save
              </Button>
            </div>
          </section>
        </div>
      </Modal>
    </>
  );
}
