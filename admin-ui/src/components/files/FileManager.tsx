import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  X,
  File as FileIcon,
  Folder,
  FolderOpen,
  FilePlus,
  FolderPlus,
  Upload,
  RefreshCw,
  Save,
  Trash2,
  Pencil,
} from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Spinner, ErrorState, EmptyState } from '@/components/ui/States';
import { useToast } from '@/providers/ToastProvider';
import { useConfirm } from '@/components/ui/ConfirmDialog';
import { fileApi, type FileNode } from '@/lib/api';
import { cn } from '@/lib/cn';

const PHP_EXT = /\.(php|phtml)$/i;

function isEditable(name: string): boolean {
  return /\.(php|phtml|html?|css|js|mjs|json|txt|xml|svg|md|ya?ml|htaccess)$/i.test(name) || !name.includes('.');
}

function Tree({
  nodes,
  depth,
  active,
  onOpen,
  onDelete,
  onRename,
}: {
  nodes: FileNode[];
  depth: number;
  active: string | null;
  onOpen: (n: FileNode) => void;
  onDelete: (n: FileNode) => void;
  onRename: (n: FileNode) => void;
}) {
  const [open, setOpen] = useState<Record<string, boolean>>({});
  return (
    <ul className="select-none">
      {nodes.map((n) => {
        const isDir = n.type === 'dir';
        const expanded = open[n.path] ?? depth < 1;
        return (
          <li key={n.path}>
            <div
              className={cn(
                'group flex items-center gap-1.5 rounded px-1.5 py-1 text-sm cursor-pointer hover:bg-surface-2',
                active === n.path && 'bg-brand/15 text-brand',
              )}
              style={{ paddingLeft: depth * 14 + 6 }}
              onClick={() => (isDir ? setOpen((o) => ({ ...o, [n.path]: !expanded })) : onOpen(n))}
            >
              {isDir ? (
                expanded ? <FolderOpen size={15} className="shrink-0 text-amber-500" /> : <Folder size={15} className="shrink-0 text-amber-500" />
              ) : (
                <FileIcon size={15} className={cn('shrink-0', PHP_EXT.test(n.name) ? 'text-violet-500' : 'text-faint')} />
              )}
              <span className="truncate flex-1">{n.name}</span>
              <button
                className="opacity-0 group-hover:opacity-100 text-faint hover:text-fg"
                title="Rename"
                onClick={(e) => {
                  e.stopPropagation();
                  onRename(n);
                }}
              >
                <Pencil size={12} />
              </button>
              <button
                className="opacity-0 group-hover:opacity-100 text-faint hover:text-danger"
                title="Delete"
                onClick={(e) => {
                  e.stopPropagation();
                  onDelete(n);
                }}
              >
                <Trash2 size={12} />
              </button>
            </div>
            {isDir && expanded && n.children && n.children.length > 0 && (
              <Tree
                nodes={n.children}
                depth={depth + 1}
                active={active}
                onOpen={onOpen}
                onDelete={onDelete}
                onRename={onRename}
              />
            )}
          </li>
        );
      })}
    </ul>
  );
}

export function FileManager({
  folder,
  title,
  onClose,
}: {
  folder: string;
  title?: string;
  onClose: () => void;
}) {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const uploadRef = useRef<HTMLInputElement>(null);

  const [activeFile, setActiveFile] = useState<string | null>(null);
  const [content, setContent] = useState('');
  const [dirty, setDirty] = useState(false);

  const treeQuery = useQuery({
    queryKey: ['lfiles', folder],
    queryFn: () => fileApi.list(folder),
  });

  const readMut = useMutation({
    mutationFn: (file: string) => fileApi.read(folder, file),
    onSuccess: (r) => {
      setContent(r.content);
      setActiveFile(r.file);
      setDirty(false);
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const saveMut = useMutation({
    mutationFn: () => fileApi.save(folder, activeFile as string, content),
    onSuccess: () => {
      toast.success('Saved');
      setDirty(false);
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const refresh = () => qc.invalidateQueries({ queryKey: ['lfiles', folder] });

  const openFile = async (n: FileNode) => {
    if (!isEditable(n.name)) {
      toast.error('Binary or non-editable file');
      return;
    }
    if (dirty && !window.confirm('Discard unsaved changes?')) return;
    readMut.mutate(n.path);
  };

  const createMut = useMutation({
    mutationFn: ({ name, kind }: { name: string; kind: 'file' | 'dir' }) =>
      fileApi.create(folder, name, kind),
    onSuccess: () => {
      toast.success('Created');
      refresh();
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const renameMut = useMutation({
    mutationFn: ({ path, newName }: { path: string; newName: string }) =>
      fileApi.rename(folder, path, newName),
    onSuccess: () => {
      toast.success('Renamed');
      refresh();
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const deleteMut = useMutation({
    mutationFn: (path: string) => fileApi.remove(folder, path),
    onSuccess: (_d, path) => {
      toast.success('Deleted');
      if (activeFile === path) {
        setActiveFile(null);
        setContent('');
      }
      refresh();
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const uploadMut = useMutation({
    mutationFn: (file: File) => fileApi.upload(folder, file, ''),
    onSuccess: () => {
      toast.success('Uploaded');
      refresh();
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const onNewFile = () => {
    const name = window.prompt('New file path (e.g. index.php or sub/page.php)');
    if (name) createMut.mutate({ name: name.trim(), kind: 'file' });
  };
  const onNewFolder = () => {
    const name = window.prompt('New folder path');
    if (name) createMut.mutate({ name: name.trim(), kind: 'dir' });
  };
  const onRename = (n: FileNode) => {
    const newName = window.prompt(`Rename “${n.name}” to:`, n.name);
    if (newName && newName !== n.name) renameMut.mutate({ path: n.path, newName: newName.trim() });
  };
  const onDelete = async (n: FileNode) => {
    const ok = await confirm({
      title: `Delete ${n.type === 'dir' ? 'folder' : 'file'}?`,
      description: `“${n.path}” will be permanently removed.`,
      confirmLabel: 'Delete',
      danger: true,
    });
    if (ok) deleteMut.mutate(n.path);
  };

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
      if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        if (activeFile && dirty) saveMut.mutate();
      }
    };
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeFile, dirty]);

  const tree = useMemo(() => treeQuery.data?.tree ?? [], [treeQuery.data]);

  return createPortal(
    <div className="fixed inset-0 z-[160] flex flex-col bg-bg">
      <div className="flex items-center justify-between gap-3 h-14 px-4 border-b border-border bg-surface shrink-0">
        <div className="flex items-center gap-2 min-w-0">
          <Folder size={18} className="text-amber-500 shrink-0" />
          <span className="font-semibold truncate">{title ?? folder}</span>
          <Badge tone="neutral">{folder}</Badge>
        </div>
        <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close">
          <X size={18} />
        </Button>
      </div>

      <div className="flex-1 flex min-h-0">
        <aside className="w-72 shrink-0 border-r border-border flex flex-col bg-surface">
          <div className="flex items-center gap-1 p-2 border-b border-border">
            <Button variant="ghost" size="icon" title="New file" onClick={onNewFile}>
              <FilePlus size={16} />
            </Button>
            <Button variant="ghost" size="icon" title="New folder" onClick={onNewFolder}>
              <FolderPlus size={16} />
            </Button>
            <Button
              variant="ghost"
              size="icon"
              title="Upload file"
              onClick={() => uploadRef.current?.click()}
            >
              <Upload size={16} />
            </Button>
            <input
              ref={uploadRef}
              type="file"
              className="hidden"
              onChange={(e) => {
                const f = e.target.files?.[0];
                if (f) uploadMut.mutate(f);
                e.target.value = '';
              }}
            />
            <div className="flex-1" />
            <Button variant="ghost" size="icon" title="Refresh" onClick={refresh}>
              <RefreshCw size={15} />
            </Button>
          </div>
          <div className="flex-1 overflow-auto p-1.5">
            {treeQuery.isLoading ? (
              <div className="grid place-items-center py-10">
                <Spinner />
              </div>
            ) : treeQuery.error ? (
              <ErrorState error={treeQuery.error} onRetry={refresh} />
            ) : tree.length === 0 ? (
              <EmptyState title="Empty folder" description="Create or upload a file to start." />
            ) : (
              <Tree
                nodes={tree}
                depth={0}
                active={activeFile}
                onOpen={openFile}
                onDelete={onDelete}
                onRename={onRename}
              />
            )}
          </div>
        </aside>

        <main className="flex-1 flex flex-col min-w-0">
          {activeFile ? (
            <>
              <div className="flex items-center justify-between gap-3 px-4 h-11 border-b border-border bg-surface-2/40">
                <div className="flex items-center gap-2 text-sm min-w-0">
                  <span className="font-mono truncate">{activeFile}</span>
                  {PHP_EXT.test(activeFile) && <Badge tone="warning">PHP — executed on serve</Badge>}
                  {dirty && <span className="h-2 w-2 rounded-full bg-amber-500" title="Unsaved changes" />}
                </div>
                <Button
                  variant="primary"
                  size="sm"
                  loading={saveMut.isPending}
                  disabled={!dirty}
                  onClick={() => saveMut.mutate()}
                >
                  <Save size={14} /> Save
                </Button>
              </div>
              {readMut.isPending ? (
                <div className="flex-1 grid place-items-center">
                  <Spinner />
                </div>
              ) : (
                <textarea
                  value={content}
                  spellCheck={false}
                  onChange={(e) => {
                    setContent(e.target.value);
                    setDirty(true);
                  }}
                  onKeyDown={(e) => {
                    if (e.key === 'Tab') {
                      e.preventDefault();
                      const ta = e.currentTarget;
                      const s = ta.selectionStart;
                      const en = ta.selectionEnd;
                      const next = content.slice(0, s) + '  ' + content.slice(en);
                      setContent(next);
                      setDirty(true);
                      requestAnimationFrame(() => {
                        ta.selectionStart = ta.selectionEnd = s + 2;
                      });
                    }
                  }}
                  className="flex-1 w-full resize-none bg-bg text-fg font-mono text-[13px] leading-relaxed p-4 outline-none"
                />
              )}
            </>
          ) : (
            <div className="flex-1 grid place-items-center">
              <EmptyState
                icon={<FileIcon size={26} />}
                title="No file open"
                description="Select a file from the tree to view and edit it. PHP runs server-side when the landing is served."
              />
            </div>
          )}
        </main>
      </div>
    </div>,
    document.body,
  );
}
