import { useRef, type ReactNode } from 'react';
import {
  flexRender,
  getCoreRowModel,
  getSortedRowModel,
  useReactTable,
  type ColumnDef,
  type SortingState,
} from '@tanstack/react-table';
import { useVirtualizer } from '@tanstack/react-virtual';
import { ChevronUp, ChevronDown, ChevronsUpDown } from 'lucide-react';
import { cn } from '@/lib/cn';
import { Skeleton } from '@/components/ui/States';

interface DataTableProps<T> {
  data: T[];
  columns: ColumnDef<T, unknown>[];
  sorting?: SortingState;
  onSortingChange?: (s: SortingState) => void;
  manualSorting?: boolean;
  loading?: boolean;
  rowHeight?: number;
  emptyState?: ReactNode;
  onRowClick?: (row: T) => void;
  getRowId?: (row: T, index: number) => string;
  maxHeight?: number;
}

export function DataTable<T>({
  data,
  columns,
  sorting,
  onSortingChange,
  manualSorting,
  loading,
  rowHeight = 40,
  emptyState,
  onRowClick,
  getRowId,
  maxHeight = 640,
}: DataTableProps<T>) {
  const containerRef = useRef<HTMLDivElement>(null);

  const table = useReactTable({
    data,
    columns,
    state: sorting ? { sorting } : undefined,
    onSortingChange: onSortingChange
      ? (updater) => {
          const next = typeof updater === 'function' ? updater(sorting ?? []) : updater;
          onSortingChange(next);
        }
      : undefined,
    manualSorting,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: manualSorting ? undefined : getSortedRowModel(),
  });

  const rows = table.getRowModel().rows;
  const virtualizer = useVirtualizer({
    count: rows.length,
    getScrollElement: () => containerRef.current,
    estimateSize: () => rowHeight,
    overscan: 12,
    getItemKey: getRowId ? (i) => getRowId(rows[i].original, i) : undefined,
  });

  const virtualRows = virtualizer.getVirtualItems();
  const totalSize = virtualizer.getTotalSize();
  const paddingTop = virtualRows.length ? virtualRows[0].start : 0;
  const paddingBottom = virtualRows.length ? totalSize - virtualRows[virtualRows.length - 1].end : 0;

  return (
    <div className="card overflow-hidden">
      <div
        ref={containerRef}
        className="overflow-auto"
        style={{ maxHeight }}
      >
        <table className="w-full border-collapse text-sm">
          <thead className="sticky top-0 z-10 bg-surface-2">
            {table.getHeaderGroups().map((hg) => (
              <tr key={hg.id} className="border-b border-border">
                {hg.headers.map((header) => {
                  const canSort = header.column.getCanSort();
                  const sorted = header.column.getIsSorted();
                  return (
                    <th
                      key={header.id}
                      style={{ width: header.getSize() ? header.getSize() : undefined }}
                      className={cn(
                        'text-left font-medium text-2xs uppercase tracking-wide text-muted px-3 py-2.5 whitespace-nowrap',
                        canSort && 'cursor-pointer select-none hover:text-fg',
                      )}
                      onClick={canSort ? header.column.getToggleSortingHandler() : undefined}
                    >
                      <span className="inline-flex items-center gap-1">
                        {flexRender(header.column.columnDef.header, header.getContext())}
                        {canSort &&
                          (sorted === 'asc' ? (
                            <ChevronUp size={13} />
                          ) : sorted === 'desc' ? (
                            <ChevronDown size={13} />
                          ) : (
                            <ChevronsUpDown size={13} className="opacity-40" />
                          ))}
                      </span>
                    </th>
                  );
                })}
              </tr>
            ))}
          </thead>
          <tbody>
            {loading ? (
              Array.from({ length: 8 }).map((_, i) => (
                <tr key={i} className="border-b border-border/60">
                  {columns.map((_c, j) => (
                    <td key={j} className="px-3 py-2.5">
                      <Skeleton className="h-3.5" />
                    </td>
                  ))}
                </tr>
              ))
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={columns.length}>{emptyState}</td>
              </tr>
            ) : (
              <>
                {paddingTop > 0 && (
                  <tr>
                    <td style={{ height: paddingTop }} colSpan={columns.length} />
                  </tr>
                )}
                {virtualRows.map((vr) => {
                  const row = rows[vr.index];
                  return (
                    <tr
                      key={row.id}
                      className={cn(
                        'border-b border-border/60 hover:bg-surface-2/60 transition-colors',
                        onRowClick && 'cursor-pointer',
                      )}
                      style={{ height: rowHeight }}
                      onClick={onRowClick ? () => onRowClick(row.original) : undefined}
                    >
                      {row.getVisibleCells().map((cell) => (
                        <td key={cell.id} className="px-3 py-2 whitespace-nowrap">
                          {flexRender(cell.column.columnDef.cell, cell.getContext())}
                        </td>
                      ))}
                    </tr>
                  );
                })}
                {paddingBottom > 0 && (
                  <tr>
                    <td style={{ height: paddingBottom }} colSpan={columns.length} />
                  </tr>
                )}
              </>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
