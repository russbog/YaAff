import { createContext, useCallback, useContext, useState, type ReactNode } from 'react';
import { CheckCircle2, AlertTriangle, Info, X } from 'lucide-react';
import { cn } from '@/lib/cn';

type ToastKind = 'success' | 'error' | 'info';
interface Toast {
  id: number;
  kind: ToastKind;
  message: string;
}

interface ToastCtx {
  push: (kind: ToastKind, message: string) => void;
  success: (m: string) => void;
  error: (m: string) => void;
  info: (m: string) => void;
}

const Ctx = createContext<ToastCtx | null>(null);

let seq = 1;

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);

  const remove = useCallback((id: number) => {
    setToasts((t) => t.filter((x) => x.id !== id));
  }, []);

  const push = useCallback(
    (kind: ToastKind, message: string) => {
      const id = seq++;
      setToasts((t) => [...t, { id, kind, message }]);
      window.setTimeout(() => remove(id), kind === 'error' ? 6000 : 3500);
    },
    [remove],
  );

  const api: ToastCtx = {
    push,
    success: (m) => push('success', m),
    error: (m) => push('error', m),
    info: (m) => push('info', m),
  };

  return (
    <Ctx.Provider value={api}>
      {children}
      <div className="fixed bottom-5 right-5 z-[200] flex flex-col gap-2 w-[min(92vw,360px)]">
        {toasts.map((t) => (
          <div
            key={t.id}
            role="status"
            className={cn(
              'card flex items-start gap-3 p-3 pr-2 shadow-pop animate-slide-up',
              t.kind === 'error' && 'border-danger/40',
              t.kind === 'success' && 'border-success/40',
            )}
          >
            <span className="mt-0.5 shrink-0">
              {t.kind === 'success' && <CheckCircle2 size={18} className="text-success" />}
              {t.kind === 'error' && <AlertTriangle size={18} className="text-danger" />}
              {t.kind === 'info' && <Info size={18} className="text-info" />}
            </span>
            <p className="text-sm leading-snug flex-1 break-words">{t.message}</p>
            <button
              onClick={() => remove(t.id)}
              className="text-faint hover:text-fg p-1 rounded transition-colors"
              aria-label="Dismiss"
            >
              <X size={15} />
            </button>
          </div>
        ))}
      </div>
    </Ctx.Provider>
  );
}

// eslint-disable-next-line react-refresh/only-export-components
export function useToast(): ToastCtx {
  const ctx = useContext(Ctx);
  if (!ctx) throw new Error('useToast must be used within ToastProvider');
  return ctx;
}
