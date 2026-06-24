import { useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Upload, TrendingUp } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Select } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { DataTable } from '@/components/data/DataTable';
import { useToast } from '@/providers/ToastProvider';
import { useBootstrap, useCan } from '@/providers/BootstrapProvider';
import { spa, conversionsApi, type ConversionImportResult } from '@/lib/api';
import { fmtDateTime, fmtMoney, toNumber } from '@/lib/format';
import type { Conversion } from '@/lib/types';

function humanize(key: string): string {
  return key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function ConversionsPage() {
  const { campaignsList } = useBootstrap();
  const canManage = useCan()('conversions.manage');
  const toast = useToast();
  const qc = useQueryClient();
  const [campId, setCampId] = useState(0);
  const [importOpen, setImportOpen] = useState(false);
  const [file, setFile] = useState<File | null>(null);
  const [result, setResult] = useState<ConversionImportResult | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['conversions', campId],
    queryFn: () => spa.conversions({ campId, limit: 1000 }),
  });

  const importMut = useMutation({
    mutationFn: (f: File) => conversionsApi.importCsv(f),
    onSuccess: (r) => {
      setResult(r);
      toast.success(`Imported ${r.imported}, skipped ${r.skipped}`);
      qc.invalidateQueries({ queryKey: ['conversions'] });
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const closeImport = () => {
    setImportOpen(false);
    setFile(null);
    setResult(null);
  };

  const rows = useMemo(() => data?.data ?? [], [data]);

  const columns = useMemo<ColumnDef<Conversion, unknown>[]>(() => {
    const keys = new Set<string>();
    rows.slice(0, 30).forEach((r) => Object.keys(r).forEach((k) => keys.add(k)));
    const priority = ['time', 'clickid', 'status', 'payout', 'revenue', 'currency'];
    const ordered = Array.from(keys).sort((a, b) => {
      const ia = priority.indexOf(a);
      const ib = priority.indexOf(b);
      return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
    });
    return ordered.map((key) => ({
      id: key,
      header: humanize(key),
      accessorFn: (r) => (r as Record<string, unknown>)[key],
      cell: ({ row }) => {
        const v = (row.original as Record<string, unknown>)[key];
        if (key === 'time') return <span className="tabular-nums text-muted">{fmtDateTime(v)}</span>;
        if (key === 'payout' || key === 'revenue') return <span className="tabular-nums">{fmtMoney(toNumber(v))}</span>;
        if (key === 'status' && v)
          return <Badge tone={String(v) === 'Purchase' ? 'success' : String(v) === 'Reject' ? 'danger' : 'info'}>{String(v)}</Badge>;
        const str = v === null || v === undefined ? '—' : String(v);
        return <span className="truncate max-w-[220px] inline-block align-middle" title={str}>{str}</span>;
      },
    }));
  }, [rows]);

  return (
    <AppShell
      title="Conversions"
      toolbar={
        <div className="flex items-center gap-2">
          <Select value={campId} onChange={(e) => setCampId(Number(e.target.value))} className="h-8 w-auto text-xs">
            <option value={0}>All campaigns</option>
            {campaignsList.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </Select>
          {canManage && (
            <Button variant="secondary" size="sm" onClick={() => setImportOpen(true)}>
              <Upload size={13} /> Import CSV
            </Button>
          )}
        </div>
      }
    >
      {error ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : (
        <div className="space-y-4">
          <div className="flex items-center gap-2">
            <Badge tone="neutral">{rows.length} conversions</Badge>
          </div>
          <DataTable
            data={rows}
            columns={columns}
            loading={isLoading}
            rowHeight={38}
            emptyState={
              <EmptyState
                icon={<TrendingUp size={26} />}
                title="No conversions"
                description="Conversions land here as postbacks and S2S events are received."
              />
            }
          />
        </div>
      )}

      <Modal
        open={importOpen}
        onClose={closeImport}
        title="Import conversions (CSV)"
        description="Required columns: clickid, status. Optional: payout, currency, revenue, tid."
        footer={
          <>
            <Button variant="ghost" onClick={closeImport}>
              {result ? 'Close' : 'Cancel'}
            </Button>
            <Button
              variant="primary"
              loading={importMut.isPending}
              disabled={!file}
              onClick={() => file && importMut.mutate(file)}
            >
              <Upload size={14} /> Import
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <input
            ref={fileRef}
            type="file"
            accept=".csv,text/csv"
            onChange={(e) => {
              setFile(e.target.files?.[0] ?? null);
              setResult(null);
            }}
            className="block w-full text-sm text-muted file:mr-3 file:rounded-md file:border-0 file:bg-brand file:px-3 file:py-1.5 file:text-brand-fg file:font-medium hover:file:opacity-90"
          />
          {file && <p className="text-xs text-muted">{file.name} · {(file.size / 1024).toFixed(1)} KB</p>}
          {result && (
            <div className="rounded-md border border-border bg-surface-2/40 p-3 text-sm space-y-1">
              <div className="flex gap-3">
                <Badge tone="success">Imported {result.imported}</Badge>
                <Badge tone="neutral">Skipped {result.skipped}</Badge>
                {result.errors.length > 0 && <Badge tone="danger">{result.errors.length} errors</Badge>}
              </div>
              {result.errors.length > 0 && (
                <ul className="mt-2 max-h-40 overflow-auto text-xs text-danger list-disc pl-4">
                  {result.errors.slice(0, 50).map((er, i) => (
                    <li key={i}>{er}</li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </div>
      </Modal>
    </AppShell>
  );
}
