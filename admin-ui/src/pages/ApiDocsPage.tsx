import { useEffect, useRef, useState } from 'react';
import { ExternalLink, Copy, KeyRound } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Field';
import { Spinner } from '@/components/ui/States';
import { useToast } from '@/providers/ToastProvider';
import { API_BASE } from '@/lib/api';

const SWAGGER_VERSION = '5.17.14';
const SWAGGER_CSS = `https://unpkg.com/swagger-ui-dist@${SWAGGER_VERSION}/swagger-ui.css`;
const SWAGGER_JS = `https://unpkg.com/swagger-ui-dist@${SWAGGER_VERSION}/swagger-ui-bundle.js`;

interface SwaggerWindow extends Window {
  SwaggerUIBundle?: (opts: { url: string; domNode: HTMLElement; deepLinking?: boolean }) => unknown;
}

function specUrl(): string {
  return new URL(API_BASE + '../api/openapi.php', window.location.origin).toString();
}

function loadScript(src: string): Promise<void> {
  return new Promise((resolve, reject) => {
    if (document.querySelector(`script[src="${src}"]`)) return resolve();
    const s = document.createElement('script');
    s.src = src;
    s.onload = () => resolve();
    s.onerror = () => reject(new Error('Failed to load Swagger UI'));
    document.head.appendChild(s);
  });
}

function loadCss(href: string) {
  if (document.querySelector(`link[href="${href}"]`)) return;
  const l = document.createElement('link');
  l.rel = 'stylesheet';
  l.href = href;
  document.head.appendChild(l);
}

function CopyField({ value }: { value: string }) {
  const toast = useToast();
  return (
    <div className="flex items-center gap-2">
      <Input readOnly value={value} className="flex-1 font-mono text-xs" />
      <Button
        variant="ghost"
        size="icon"
        className="h-9 w-9 shrink-0"
        title="Copy"
        onClick={() => {
          navigator.clipboard?.writeText(value);
          toast.success('Copied');
        }}
      >
        <Copy size={15} />
      </Button>
    </div>
  );
}

export function ApiDocsPage() {
  const mount = useRef<HTMLDivElement>(null);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const url = specUrl();
  const base = new URL(API_BASE + '../api/rest.php/', window.location.origin).toString();

  useEffect(() => {
    let cancelled = false;
    loadCss(SWAGGER_CSS);
    loadScript(SWAGGER_JS)
      .then(() => {
        if (cancelled) return;
        const w = window as SwaggerWindow;
        if (w.SwaggerUIBundle && mount.current) {
          w.SwaggerUIBundle({ url, domNode: mount.current, deepLinking: true });
          setLoading(false);
        } else {
          setFailed(true);
          setLoading(false);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setFailed(true);
          setLoading(false);
        }
      });
    return () => {
      cancelled = true;
    };
  }, [url]);

  return (
    <AppShell
      title="REST API"
      toolbar={
        <a href={url} target="_blank" rel="noreferrer">
          <Button variant="secondary" size="sm">
            <ExternalLink size={14} /> openapi.json
          </Button>
        </a>
      }
    >
      <div className="space-y-4">
        <section className="rounded-lg border border-border bg-surface p-4 shadow-soft space-y-3">
          <div className="flex items-center gap-2 text-sm font-semibold text-fg">
            <KeyRound size={15} /> Authentication
          </div>
          <p className="text-sm text-muted leading-relaxed">
            Authenticate every request with <code className="text-fg">Authorization: Bearer &lt;token&gt;</code> — either a
            user's <code className="text-fg">api_token</code> (Users page) or the master{' '}
            <code className="text-fg">apiToken</code> from <code className="text-fg">settings.php</code>.
          </p>
          <div>
            <div className="text-2xs uppercase tracking-wide text-muted mb-1">Base path</div>
            <CopyField value={`${base}<type>[/<id>]`} />
          </div>
        </section>

        <section className="rounded-lg border border-border bg-surface shadow-soft overflow-hidden">
          {loading && (
            <div className="py-16 grid place-items-center">
              <Spinner className="h-6 w-6" />
            </div>
          )}
          {failed && (
            <div className="p-6 text-sm text-muted">
              Could not load the interactive API explorer.{' '}
              <a className="text-brand underline" href={url} target="_blank" rel="noreferrer">
                Open the raw OpenAPI spec
              </a>
              .
            </div>
          )}
          <div ref={mount} className="yaaff-swagger" />
        </section>
      </div>
    </AppShell>
  );
}
