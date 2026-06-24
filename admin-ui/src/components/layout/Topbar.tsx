import { PanelLeft, Sun, Moon, LogOut, RefreshCw } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { useTheme } from '@/providers/ThemeProvider';
import { useBootstrap } from '@/providers/BootstrapProvider';
import { API_BASE, APP_VERSION } from '@/lib/api';

export function Topbar({
  title,
  onToggleSidebar,
  children,
}: {
  title: string;
  onToggleSidebar: () => void;
  children?: React.ReactNode;
}) {
  const { theme, toggle } = useTheme();
  const { user } = useBootstrap();
  const qc = useQueryClient();

  return (
    <header className="h-14 shrink-0 border-b border-border bg-surface/80 backdrop-blur flex items-center gap-3 px-4 sticky top-0 z-30">
      <Button variant="ghost" size="icon" onClick={onToggleSidebar} aria-label="Toggle sidebar">
        <PanelLeft size={18} />
      </Button>
      <h1 className="text-base font-semibold">{title}</h1>
      <div className="flex-1 flex items-center justify-end gap-2">
        {children}
        <Button
          variant="ghost"
          size="icon"
          onClick={() => qc.invalidateQueries()}
          title="Refresh data"
          aria-label="Refresh"
        >
          <RefreshCw size={16} />
        </Button>
        <Button variant="ghost" size="icon" onClick={toggle} aria-label="Toggle theme">
          {theme === 'dark' ? <Sun size={16} /> : <Moon size={16} />}
        </Button>
        <div className="hidden sm:flex items-center gap-2 pl-2">
          {user && (
            <span className="text-xs text-muted">
              {user.name} · <span className="text-faint">{user.role}</span>
            </span>
          )}
          <Badge tone="neutral">v{APP_VERSION}</Badge>
        </div>
        <a href={`${API_BASE}logout.php`} title="Logout">
          <Button variant="ghost" size="icon" aria-label="Logout">
            <LogOut size={16} />
          </Button>
        </a>
      </div>
    </header>
  );
}
