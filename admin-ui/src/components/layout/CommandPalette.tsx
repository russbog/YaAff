import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useNavigate } from 'react-router-dom';
import { Search, CornerDownLeft } from 'lucide-react';
import { useBootstrap } from '@/providers/BootstrapProvider';
import { NavIcon } from '@/lib/icons';
import { cn } from '@/lib/cn';

/** Window event other components (e.g. the Topbar button) dispatch to open the palette. */
export const OPEN_COMMAND_PALETTE = 'yaaff:open-command-palette';

interface Command {
  id: string;
  label: string;
  hint?: string;
  icon: string;
  keywords: string;
  run: () => void;
}

export function CommandPalette() {
  const { nav } = useBootstrap();
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLDivElement>(null);

  const commands = useMemo<Command[]>(() => {
    const go: Command[] = nav.map((n) => ({
      id: `go:${n.key}`,
      label: n.label,
      hint: n.group ? `Go · ${n.group}` : 'Go',
      icon: n.icon,
      keywords: `${n.label} ${n.group ?? ''} ${n.key}`.toLowerCase(),
      run: () => navigate(`/${n.key}`),
    }));
    // Quick "New …" jumps for catalog entities (open the list where + New lives).
    const NEW: { key: string; label: string; icon: string }[] = [
      { key: 'offers', label: 'Offer', icon: 'target' },
      { key: 'landings', label: 'Landing', icon: 'file' },
      { key: 'sources', label: 'Source', icon: 'broadcast' },
      { key: 'networks', label: 'Network', icon: 'sitemap' },
      { key: 'domains', label: 'Domain', icon: 'globe' },
    ];
    const allowed = new Set(nav.map((n) => n.key));
    const create: Command[] = NEW.filter((n) => allowed.has(n.key)).map((n) => ({
      id: `new:${n.key}`,
      label: `New ${n.label}`,
      hint: 'Create',
      icon: n.icon,
      keywords: `new create add ${n.label} ${n.key}`.toLowerCase(),
      run: () => navigate(`/${n.key}?new=1`),
    }));
    return [...create, ...go];
  }, [nav, navigate]);

  const results = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return commands;
    return commands.filter((c) => q.split(/\s+/).every((part) => c.keywords.includes(part)));
  }, [commands, query]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setOpen((o) => !o);
      }
    };
    const onOpen = () => setOpen(true);
    window.addEventListener('keydown', onKey);
    window.addEventListener(OPEN_COMMAND_PALETTE, onOpen);
    return () => {
      window.removeEventListener('keydown', onKey);
      window.removeEventListener(OPEN_COMMAND_PALETTE, onOpen);
    };
  }, []);

  useEffect(() => {
    if (open) {
      setQuery('');
      setActive(0);
      // Focus after the portal mounts.
      requestAnimationFrame(() => inputRef.current?.focus());
    }
  }, [open]);

  useEffect(() => setActive(0), [query]);

  if (!open) return null;

  const close = () => setOpen(false);
  const exec = (c: Command | undefined) => {
    if (!c) return;
    close();
    c.run();
  };

  const onKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      close();
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActive((a) => Math.min(a + 1, results.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActive((a) => Math.max(a - 1, 0));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      exec(results[active]);
    }
  };

  return createPortal(
    <div className="fixed inset-0 z-[200] flex items-start justify-center p-4 pt-[12vh]">
      <div className="fixed inset-0 bg-black/55 backdrop-blur-sm animate-fade-in" onClick={close} aria-hidden />
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Command palette"
        className="relative card shadow-pop w-full max-w-xl animate-slide-up overflow-hidden"
        onKeyDown={onKeyDown}
      >
        <div className="flex items-center gap-2.5 px-4 border-b border-border">
          <Search size={16} className="text-faint shrink-0" />
          <input
            ref={inputRef}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search pages and actions…"
            className="flex-1 h-12 bg-transparent text-sm outline-none placeholder:text-faint"
          />
          <kbd className="hidden sm:inline-block rounded border border-border bg-surface-2 px-1.5 py-0.5 text-2xs text-faint">
            Esc
          </kbd>
        </div>
        <div ref={listRef} className="max-h-[52vh] overflow-y-auto py-1.5">
          {results.length === 0 ? (
            <p className="px-4 py-6 text-center text-xs text-faint">No matches.</p>
          ) : (
            results.map((c, i) => (
              <button
                key={c.id}
                type="button"
                onMouseEnter={() => setActive(i)}
                onClick={() => exec(c)}
                className={cn(
                  'flex w-full items-center gap-3 px-4 h-10 text-left text-sm transition-colors',
                  i === active ? 'bg-brand/15 text-brand' : 'text-fg hover:bg-surface-2',
                )}
              >
                <NavIcon name={c.icon} size={16} />
                <span className="flex-1 truncate">{c.label}</span>
                {c.hint && <span className="text-2xs text-faint">{c.hint}</span>}
                {i === active && <CornerDownLeft size={13} className="text-faint" />}
              </button>
            ))
          )}
        </div>
      </div>
    </div>,
    document.body,
  );
}
