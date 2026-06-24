import { useEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/lib/cn';

export interface MenuItem {
  label: string;
  icon?: ReactNode;
  onClick: () => void;
  danger?: boolean;
}

export function Menu({ trigger, items }: { trigger: ReactNode; items: MenuItem[] }) {
  const [open, setOpen] = useState(false);
  const [pos, setPos] = useState({ top: 0, left: 0 });
  const btnRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!open) return;
    const close = () => setOpen(false);
    window.addEventListener('scroll', close, true);
    window.addEventListener('resize', close);
    document.addEventListener('click', close);
    return () => {
      window.removeEventListener('scroll', close, true);
      window.removeEventListener('resize', close);
      document.removeEventListener('click', close);
    };
  }, [open]);

  const toggle = (e: React.MouseEvent) => {
    e.stopPropagation();
    const rect = btnRef.current?.getBoundingClientRect();
    if (rect) setPos({ top: rect.bottom + 4, left: Math.max(8, rect.right - 176) });
    setOpen((o) => !o);
  };

  return (
    <>
      <button
        ref={btnRef}
        onClick={toggle}
        className="grid place-items-center h-7 w-7 rounded-md text-muted hover:text-fg hover:bg-surface-2 transition-colors"
        aria-haspopup="menu"
      >
        {trigger}
      </button>
      {open &&
        createPortal(
          <div
            role="menu"
            className="fixed z-[160] w-44 card shadow-pop py-1 animate-fade-in"
            style={{ top: pos.top, left: pos.left }}
            onClick={(e) => e.stopPropagation()}
          >
            {items.map((it) => (
              <button
                key={it.label}
                role="menuitem"
                onClick={() => {
                  setOpen(false);
                  it.onClick();
                }}
                className={cn(
                  'flex w-full items-center gap-2.5 px-3 py-1.5 text-sm transition-colors',
                  it.danger
                    ? 'text-danger hover:bg-danger/10'
                    : 'text-fg/90 hover:bg-surface-2',
                )}
              >
                {it.icon && <span className="shrink-0">{it.icon}</span>}
                {it.label}
              </button>
            ))}
          </div>,
          document.body,
        )}
    </>
  );
}
