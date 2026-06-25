import { useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import {
  Plus,
  MoreVertical,
  Pencil,
  Trash2,
  Search,
  FolderOpen,
  UploadCloud,
  Globe,
  RefreshCw,
  Wrench,
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Field';
import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { Menu } from '@/components/ui/Menu';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { DataTable } from '@/components/data/DataTable';
import { EntityForm, type FieldValues } from '@/components/entity/EntityForm';
import { DomainForm } from '@/components/domains/DomainForm';
import { FileManager } from '@/components/files/FileManager';
import { ZipUploadModal } from '@/components/files/ZipUploadModal';
import { DomainToolsModal } from '@/components/domains/DomainToolsModal';
import { DomainStatusBadge } from '@/components/domains/DomainStatusBadge';
import { useToast } from '@/providers/ToastProvider';
import { useConfirm } from '@/components/ui/ConfirmDialog';
import { useBootstrap, useCan } from '@/providers/BootstrapProvider';
import { entityApi, domainStatusApi, type DomainStatusMap } from '@/lib/api';
import { fmtDateTime } from '@/lib/format';
import type { EntityRecord } from '@/lib/types';

export function EntityPage({ type }: { type: string }) {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const { entitySchemas } = useBootstrap();
  const canManage = useCan()(`${type}.manage`);
  const schema = entitySchemas[type];

  const [search, setSearch] = useState('');
  const [editing, setEditing] = useState<EntityRecord | null | undefined>(undefined); // undefined = closed
  const valuesRef = useRef<FieldValues>({});
  const isLandings = type === 'landings';
  const isDomains = type === 'domains';
  const [filesFolder, setFilesFolder] = useState<string | null>(null);
  const [zipOpen, setZipOpen] = useState(false);
  const [domainTools, setDomainTools] = useState<EntityRecord | null>(null);
  const [searchParams, setSearchParams] = useSearchParams();

  // Deep-link: command palette "New …" navigates with ?new=1 to open the form.
  useEffect(() => {
    if (searchParams.get('new') === '1' && canManage) {
      setEditing(null);
      const next = new URLSearchParams(searchParams);
      next.delete('new');
      setSearchParams(next, { replace: true });
    }
  }, [searchParams, canManage, setSearchParams]);

  const landingFolder = (r: EntityRecord): string | null => {
    const s = (r.settings ?? {}) as { type?: string; path?: string };
    return s.type !== 'remote' && s.path ? s.path : null;
  };

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['entity', type, 'list'],
    queryFn: () => entityApi.list(type),
  });

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['entity', type] });
  };

  const saveMut = useMutation({
    mutationFn: (body: Record<string, unknown>) => entityApi.save(type, body),
    onSuccess: () => {
      toast.success(`${schema?.singular ?? 'Item'} saved`);
      setEditing(undefined);
      invalidate();
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const removeMut = useMutation({
    mutationFn: (id: number) => entityApi.remove(type, id),
    onSuccess: () => {
      toast.success('Deleted');
      invalidate();
    },
    onError: (e: Error) => toast.error(e.message),
  });

  // Live domain health (DNS/SSL). Cached map, polled while the page is open.
  const { data: statusData, refetch: refetchStatus } = useQuery({
    queryKey: ['domain-status'],
    queryFn: () => domainStatusApi.all(),
    enabled: isDomains,
    refetchInterval: isDomains ? 30_000 : false,
  });
  const statusMap: DomainStatusMap = statusData?.statuses ?? {};

  const recheckMut = useMutation({
    mutationFn: (id: number) => domainStatusApi.check(id),
    onSuccess: (r) => {
      toast.success(`Checked: ${r.status.detail}`);
      refetchStatus();
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const recheckAllMut = useMutation({
    mutationFn: () => domainStatusApi.checkAll(),
    onSuccess: (r) => {
      toast.success(`Re-checked ${Object.keys(r.statuses).length} domain(s)`);
      refetchStatus();
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const fixMut = useMutation({
    mutationFn: (id: number) => domainStatusApi.fix(id),
    onSuccess: (r) => {
      if (r.fix.queued) toast.info(r.fix.detail);
      else if (r.fix.ok) toast.success(r.fix.detail);
      else toast.error(r.fix.detail);
      refetchStatus();
    },
    onError: (e: Error) => toast.error(e.message),
  });

  // Bulk create (domains): one record per comma-separated hostname.
  const bulkSaveMut = useMutation({
    mutationFn: async (bodies: Record<string, unknown>[]) => {
      for (const b of bodies) await entityApi.save(type, b);
      return bodies.length;
    },
    onSuccess: (n) => {
      toast.success(n > 1 ? `${n} domains saved` : `${schema?.singular ?? 'Item'} saved`);
      setEditing(undefined);
      invalidate();
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const items = useMemo(() => {
    const all = data?.items ?? [];
    const q = search.trim().toLowerCase();
    return q ? all.filter((i) => i.name?.toLowerCase().includes(q) || i.group?.toLowerCase().includes(q)) : all;
  }, [data, search]);

  const columns = useMemo<ColumnDef<EntityRecord, unknown>[]>(
    () => [
      {
        id: 'name',
        header: 'Name',
        accessorKey: 'name',
        cell: ({ row }) => <span className="font-medium">{row.original.name}</span>,
      },
      {
        id: 'group',
        header: 'Group',
        accessorKey: 'group',
        cell: ({ row }) =>
          row.original.group ? <Badge tone="neutral">{row.original.group}</Badge> : <span className="text-faint">—</span>,
      },
      ...(isDomains
        ? [
            {
              id: 'status',
              header: 'Status',
              enableSorting: false,
              cell: ({ row }: { row: { original: EntityRecord } }) => (
                <DomainStatusBadge status={statusMap[String(row.original.id)]} />
              ),
            } as ColumnDef<EntityRecord, unknown>,
            {
              id: 'config',
              header: 'Config',
              enableSorting: false,
              cell: ({ row }: { row: { original: EntityRecord } }) => {
                const s = (row.original.settings ?? {}) as {
                  type?: string;
                  index_allowed?: boolean;
                  campaign_id?: number | string | null;
                  intercept_404?: boolean;
                };
                const hasDefault = s.campaign_id != null && Number(s.campaign_id) > 0;
                return (
                  <div className="flex flex-wrap gap-1">
                    {s.type && s.type !== 'regular' && <Badge tone="info">{s.type}</Badge>}
                    <Badge tone={s.index_allowed ? 'success' : 'neutral'}>
                      {s.index_allowed ? 'indexable' : 'noindex'}
                    </Badge>
                    {hasDefault && <Badge tone="brand">index page</Badge>}
                    {s.intercept_404 && <Badge tone="warning">404→default</Badge>}
                  </div>
                );
              },
            } as ColumnDef<EntityRecord, unknown>,
          ]
        : []),
      {
        id: 'updated_at',
        header: 'Updated',
        accessorKey: 'updated_at',
        cell: ({ row }) => <span className="text-muted tabular-nums">{fmtDateTime(row.original.updated_at)}</span>,
      },
      {
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
                { label: 'Edit', icon: <Pencil size={14} />, onClick: () => setEditing(r) },
                ...(isLandings && landingFolder(r)
                  ? [
                      {
                        label: 'Files',
                        icon: <FolderOpen size={14} />,
                        onClick: () => setFilesFolder(landingFolder(r)),
                      },
                    ]
                  : []),
                ...(isDomains
                  ? [
                      {
                        label: 'Recheck status',
                        icon: <RefreshCw size={14} />,
                        onClick: () => recheckMut.mutate(r.id),
                      },
                      {
                        label: 'Fix now',
                        icon: <Wrench size={14} />,
                        onClick: () => fixMut.mutate(r.id),
                      },
                      {
                        label: 'DNS / Cloudflare',
                        icon: <Globe size={14} />,
                        onClick: () => setDomainTools(r),
                      },
                    ]
                  : []),
                ...(canManage
                  ? [
                      {
                        label: 'Delete',
                        icon: <Trash2 size={14} />,
                        danger: true,
                        onClick: async () => {
                          const ok = await confirm({
                            title: `Delete ${schema?.singular ?? 'item'}?`,
                            description: `“${r.name}” will be permanently removed.`,
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
      },
    ],
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [canManage, schema, isLandings, isDomains, statusMap],
  );

  if (!schema) {
    return (
      <AppShell title={type}>
        <ErrorState error={new Error(`Unknown entity type: ${type}`)} />
      </AppShell>
    );
  }

  const submit = () => {
    const body: Record<string, unknown> = { ...valuesRef.current };
    if (editing) {
      body.id = editing.id;
      saveMut.mutate(body);
      return;
    }
    if (isDomains) {
      const hosts = Array.from(
        new Set(
          String(body.name ?? '')
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean),
        ),
      );
      if (hosts.length === 0) {
        toast.error('Enter at least one domain');
        return;
      }
      bulkSaveMut.mutate(hosts.map((h) => ({ ...body, name: h })));
      return;
    }
    saveMut.mutate(body);
  };

  return (
    <AppShell
      title={schema.title}
      toolbar={
        canManage && (
          <div className="flex items-center gap-2">
            {isLandings && (
              <Button variant="secondary" size="sm" onClick={() => setZipOpen(true)}>
                <UploadCloud size={15} /> Upload ZIP
              </Button>
            )}
            {isDomains && (
              <Button
                variant="secondary"
                size="sm"
                onClick={() => recheckAllMut.mutate()}
                disabled={recheckAllMut.isPending}
              >
                <RefreshCw size={15} className={recheckAllMut.isPending ? 'animate-spin' : undefined} /> Recheck all
              </Button>
            )}
            <Button variant="primary" size="sm" onClick={() => setEditing(null)}>
              <Plus size={15} /> New {schema.singular}
            </Button>
          </div>
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
              <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search…" className="pl-8" />
            </div>
            <Badge tone="neutral">
              {items.length} {schema.title.toLowerCase()}
            </Badge>
          </div>

          <DataTable
            data={items}
            columns={columns}
            loading={isLoading}
            getRowId={(r) => String(r.id)}
            onRowClick={(r) => setEditing(r)}
            emptyState={
              <EmptyState
                title={`No ${schema.title.toLowerCase()} yet`}
                description={`Create your first ${schema.singular.toLowerCase()} to get started.`}
                action={
                  canManage && (
                    <Button variant="primary" onClick={() => setEditing(null)}>
                      <Plus size={15} /> New {schema.singular}
                    </Button>
                  )
                }
              />
            }
          />
        </div>
      )}

      <Modal
        open={editing !== undefined}
        onClose={() => setEditing(undefined)}
        size="lg"
        title={editing ? `Edit ${schema.singular}` : `New ${schema.singular}`}
        footer={
          <>
            <Button variant="ghost" onClick={() => setEditing(undefined)}>
              Cancel
            </Button>
            <Button
              variant="primary"
              loading={saveMut.isPending || bulkSaveMut.isPending}
              onClick={submit}
              disabled={!canManage}
            >
              Save
            </Button>
          </>
        }
      >
        {editing !== undefined &&
          (isDomains ? (
            <DomainForm record={editing ?? null} onValuesChange={(v) => (valuesRef.current = v)} />
          ) : (
            <EntityForm
              fields={schema.fields}
              record={editing ?? null}
              onValuesChange={(v) => (valuesRef.current = v)}
            />
          ))}
      </Modal>

      {isLandings && (
        <ZipUploadModal
          open={zipOpen}
          onClose={() => setZipOpen(false)}
          onUploaded={(folder) => {
            setZipOpen(false);
            setFilesFolder(folder);
          }}
        />
      )}
      {filesFolder && (
        <FileManager folder={filesFolder} onClose={() => setFilesFolder(null)} />
      )}
      {domainTools && (
        <DomainToolsModal domain={domainTools} onClose={() => setDomainTools(null)} />
      )}
    </AppShell>
  );
}
