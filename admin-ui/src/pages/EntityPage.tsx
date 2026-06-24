import { useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Plus, MoreVertical, Pencil, Trash2, Search } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Field';
import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { Menu } from '@/components/ui/Menu';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { DataTable } from '@/components/data/DataTable';
import { EntityForm, type FieldValues } from '@/components/entity/EntityForm';
import { useToast } from '@/providers/ToastProvider';
import { useConfirm } from '@/components/ui/ConfirmDialog';
import { useBootstrap, useCan } from '@/providers/BootstrapProvider';
import { entityApi } from '@/lib/api';
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
    [canManage, schema],
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
    if (editing) body.id = editing.id;
    saveMut.mutate(body);
  };

  return (
    <AppShell
      title={schema.title}
      toolbar={
        canManage && (
          <Button variant="primary" size="sm" onClick={() => setEditing(null)}>
            <Plus size={15} /> New {schema.singular}
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
            <Button variant="primary" loading={saveMut.isPending} onClick={submit} disabled={!canManage}>
              Save
            </Button>
          </>
        }
      >
        {editing !== undefined && (
          <EntityForm
            fields={schema.fields}
            record={editing ?? null}
            onValuesChange={(v) => (valuesRef.current = v)}
          />
        )}
      </Modal>
    </AppShell>
  );
}
