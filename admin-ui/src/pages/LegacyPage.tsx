import { ExternalLink } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { API_BASE } from '@/lib/api';

// Transitional host for specialized admin tools not yet rebuilt natively. The
// legacy PHP screen is embedded full-bleed and themed by its own stylesheet so
// no functionality is lost during the migration.
export function LegacyPage({
  title,
  file,
  description,
}: {
  title: string;
  file: string;
  description?: string;
}) {
  const src = `${API_BASE}${file}`;
  return (
    <AppShell
      title={title}
      toolbar={
        <a href={src} target="_blank" rel="noreferrer">
          <Button variant="secondary" size="sm">
            <ExternalLink size={14} /> Open standalone
          </Button>
        </a>
      }
    >
      <div className="mb-4 flex items-center gap-3">
        <Badge tone="info">Classic module</Badge>
        {description && <p className="text-sm text-muted">{description}</p>}
      </div>
      <Card className="overflow-hidden p-0">
        <iframe
          title={title}
          src={src}
          className="w-full bg-white"
          style={{ height: 'calc(100vh - 220px)', minHeight: 480 }}
        />
      </Card>
    </AppShell>
  );
}
