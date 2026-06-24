import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Plus, Search, MoreVertical, Pencil, Copy, Trash2, Sliders, Megaphone } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/Button';
import { Input, FormRow } from '@/components/ui/Field';
import { Modal } from '@/components/ui/Modal';
import { Menu } from '@/components/ui/Menu';
import { Badge } from '@/components/ui/Badge';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { DataTable } from '@/components/data/DataTable';
import { useToast } from '@/providers/ToastProvider';
import { useConfirm } from '@/components/ui/ConfirmDialog';
import { useBootstrap, useCan } from '@/providers/BootstrapProvider';
import { spa, campaignApi } from '@/lib/api';
import { fmtStat, toNumber } from '@/lib/format';
import type { CampaignRow } from '@/lib/types';

type DialogState =
  | { kind: 'create' }
  | { kind: 'rename'; row: CampaignRow }
  | { kind: 'duplicate'; row: CampaignRow }
  | null;

export function CampaignsPage() {
  const toast = useToast();
  const confirm = useConfirm();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const canManage = useCan()('campaigns.manage');
  const { statFields } = useBootstrap();
  const [search, setSearch] = useState('');
  const [dialog, setDialog] = useState<DialogState>(null);
  const [name, setName] = useState('');

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['campaigns'],
    queryFn: () => spa.campaigns(),
  });

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['campaigns'] });
    qc.invalidateQueries({ queryKey: ['bootstrap'] });
  };

  const createMut = useMutation({
    mutationFn: (n: string) => campaignApi.create(n),
    onSuccess: (r) => {
      if (r.error) return toast.error(r.result);
      toast.success('Campaign created');
      setDialog(null);
      invalidate();
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const renameMut = useMutation({
    mutationFn: ({ id, n }: { id: number; n: string }) => campaignApi.rename(id, n),
    onSuccess: (r) => {
      if (r.error) return toast.error(r.result);
      toast.success('Renamed');
      setDialog(null);
      invalidate();
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const dupMut = useMutation({
    mutationFn: ({ id, n }: { id: number; n: string }) => campaignApi.duplicate(id, n),
    onSuccess: (r) => {
      if (r.error) return toast.error(r.result);
      toast.success('Duplicated');
      setDialog(null);
      invalidate();
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const removeMut = useMutation({
    mutationFn: (id: number) => campaignApi.remove(id),
    onSuccess: (r) => {
      if (r.error) return toast.error(r.result);
      toast.success('Campaign deleted');
      invalidate();
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const openAdvanced = (row: CampaignRow) => navigate(`/campaign/${row.id}`);

  const rows = useMemo(() => {
    const all = data?.rows ?? [];
    const q = search.trim().toLowerCase();
    return q ? all.filter((r) => r.name?.toLowerCase().includes(q)) : all;
  }, [data, search]);

  const columns = useMemo<ColumnDef<CampaignRow, unknown>[]>(() => {
    const nameCol: ColumnDef<CampaignRow, unknown> = {
      id: 'name',
      header: 'Campaign',
      accessorKey: 'name',
      size: 280,
      cell: ({ row }) => (
        <div className="flex items-center gap-2 min-w-0">
          <button
            onClick={() => openAdvanced(row.original)}
            className="font-medium text-fg hover:text-brand transition-colors truncate text-left"
            title="Open campaign settings"
          >
            {row.original.name}
          </button>
        </div>
      ),
    };
    const statCols: ColumnDef<CampaignRow, unknown>[] = statFields.map((f) => ({
      id: f.field,
      header: f.title,
      accessorFn: (r) => toNumber(r[f.field]),
      sortingFn: 'basic',
      cell: ({ row }) => <span className="tabular-nums text-fg/90">{fmtStat(row.original[f.field], f.kind)}</span>,
    }));
    const actionsCol: ColumnDef<CampaignRow, unknown> = {
      id: 'actions',
      header: '',
      size: 48,
      enableSorting: false,
      cell: ({ row }) => {
        const r = row.original;
        return (
          <Menu
            trigger={<MoreVertical size={16} />}
            items={[
              { label: 'Open settings', icon: <Sliders size={14} />, onClick: () => openAdvanced(r) },
              ...(canManage
                ? [
                    {
                      label: 'Rename',
                      icon: <Pencil size={14} />,
                      onClick: () => {
                        setName(r.name);
                        setDialog({ kind: 'rename', row: r });
                      },
                    },
                    {
                      label: 'Duplicate',
                      icon: <Copy size={14} />,
                      onClick: () => {
                        setName(`${r.name} copy`);
                        setDialog({ kind: 'duplicate', row: r });
                      },
                    },
                    {
                      label: 'Delete',
                      icon: <Trash2 size={14} />,
                      danger: true,
                      onClick: async () => {
                        const ok = await confirm({
                          title: 'Delete campaign?',
                          description: `“${r.name}” and its routing config will be permanently removed. Traffic stats are retained.`,
                          confirmLabel: 'Delete',
                          danger: true,
                        });
                        if (ok) removeMut.mutate(r.id);
                      },
                    },
                  ]
                : []),
            ]}
          />
        );
      },
    };
    return [nameCol, ...statCols, actionsCol];
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statFields, canManage]);

  const submit = () => {
    const n = name.trim();
    if (!n) return toast.error('Name is required');
    if (dialog?.kind === 'create') createMut.mutate(n);
    if (dialog?.kind === 'rename') renameMut.mutate({ id: dialog.row.id, n });
    if (dialog?.kind === 'duplicate') dupMut.mutate({ id: dialog.row.id, n });
  };

  return (
    <AppShell
      title="Campaigns"
      toolbar={
        canManage && (
          <Button
            variant="primary"
            size="sm"
            onClick={() => {
              setName('');
              setDialog({ kind: 'create' });
            }}
          >
            <Plus size={15} /> New campaign
          </Button>
        )
      }
    >
      {error ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : (
        <div className="space-y-4">
          <div className="flex items-center justify-between gap-3">
            <div className="relative w-full max-w-xs">
              <Search size={15} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-faint" />
              <Input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search campaigns…"
                className="pl-8"
              />
            </div>
            <div className="flex items-center gap-2 text-xs text-muted">
              <Badge tone="neutral">{rows.length} campaigns</Badge>
            </div>
          </div>

          <DataTable
            data={rows}
            columns={columns}
            loading={isLoading}
            getRowId={(r) => String(r.id)}
            onRowClick={(r) => openAdvanced(r)}
            emptyState={
              <EmptyState
                icon={<Megaphone size={26} />}
                title="No campaigns yet"
                description="Create your first campaign to start routing traffic, building flows and tracking conversions."
                action={
                  canManage && (
                    <Button
                      variant="primary"
                      onClick={() => {
                        setName('');
                        setDialog({ kind: 'create' });
                      }}
                    >
                      <Plus size={15} /> New campaign
                    </Button>
                  )
                }
              />
            }
          />
        </div>
      )}

      <Modal
        open={dialog !== null}
        onClose={() => setDialog(null)}
        title={
          dialog?.kind === 'create'
            ? 'New campaign'
            : dialog?.kind === 'rename'
              ? 'Rename campaign'
              : 'Duplicate campaign'
        }
        description={
          dialog?.kind === 'duplicate'
            ? 'Clones all routing rules and flows into a new campaign.'
            : undefined
        }
        footer={
          <>
            <Button variant="ghost" onClick={() => setDialog(null)}>
              Cancel
            </Button>
            <Button
              variant="primary"
              loading={createMut.isPending || renameMut.isPending || dupMut.isPending}
              onClick={submit}
            >
              {dialog?.kind === 'create' ? 'Create' : dialog?.kind === 'rename' ? 'Save' : 'Duplicate'}
            </Button>
          </>
        }
      >
        <FormRow label="Campaign name" required>
          <Input
            autoFocus
            value={name}
            onChange={(e) => setName(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && submit()}
            placeholder="e.g. Nutra — DE — Facebook"
          />
        </FormRow>
      </Modal>
    </AppShell>
  );
}
