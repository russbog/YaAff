import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, ExternalLink } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/Button';
import { API_BASE } from '@/lib/api';

// In-shell host for deep technical tools that run on the existing PHP engine
// (campaign / TDS builder, blacklist feeds, data utilities, file editor). The
// page is loaded chrome-free via ?embed=1 so its classic top bar and nav are
// suppressed and it blends into the modern shell — the legacy standalone
// interface is no longer used as an entry point.
export function LegacyPage({
  title,
  file,
  params,
  backTo,
}: {
  title: string;
  file: string;
  params?: Record<string, string | number>;
  backTo?: string;
}) {
  const navigate = useNavigate();
  const src = useMemo(() => {
    const u = new URL(API_BASE + file, window.location.origin);
    u.searchParams.set('embed', '1');
    if (params) {
      for (const [k, v] of Object.entries(params)) u.searchParams.set(k, String(v));
    }
    return u.toString();
  }, [file, params]);

  const standalone = useMemo(() => {
    const u = new URL(API_BASE + file, window.location.origin);
    if (params) for (const [k, v] of Object.entries(params)) u.searchParams.set(k, String(v));
    return u.toString();
  }, [file, params]);

  return (
    <AppShell
      title={title}
      toolbar={
        <div className="flex items-center gap-2">
          {backTo && (
            <Button variant="ghost" size="sm" onClick={() => navigate(backTo)}>
              <ArrowLeft size={14} /> Back
            </Button>
          )}
          <a href={standalone} target="_blank" rel="noreferrer">
            <Button variant="secondary" size="sm">
              <ExternalLink size={14} /> Open full screen
            </Button>
          </a>
        </div>
      }
    >
      <div className="rounded-lg border border-border overflow-hidden bg-white shadow-soft">
        <iframe
          title={title}
          src={src}
          className="w-full bg-white"
          style={{ height: 'calc(100vh - 7.5rem)', minHeight: 480 }}
        />
      </div>
    </AppShell>
  );
}
