import { NavLink } from 'react-router-dom';
import { useBootstrap } from '@/providers/BootstrapProvider';
import { NavIcon } from '@/lib/icons';
import { cn } from '@/lib/cn';

export function Sidebar({ collapsed }: { collapsed: boolean }) {
  const { nav } = useBootstrap();

  return (
    <aside
      className={cn(
        'shrink-0 border-r border-border bg-surface flex flex-col transition-all duration-200',
        collapsed ? 'w-[64px]' : 'w-[232px]',
      )}
    >
      <div className="h-14 flex items-center gap-2.5 px-4 border-b border-border">
        <span className="grid place-items-center h-8 w-8 rounded-md bg-brand text-brand-fg font-bold shrink-0">
          Y
        </span>
        {!collapsed && (
          <div className="min-w-0">
            <div className="text-sm font-semibold leading-tight">YaAff</div>
            <div className="text-2xs text-faint leading-tight truncate">Command Center</div>
          </div>
        )}
      </div>

      <nav className="flex-1 overflow-y-auto py-3 px-2 space-y-0.5">
        {nav.map((item) => (
          <NavLink
            key={item.key}
            to={`/${item.key}`}
            title={collapsed ? item.label : undefined}
            className={({ isActive }) =>
              cn(
                'flex items-center gap-3 rounded-md px-2.5 h-9 text-sm font-medium transition-colors group',
                collapsed && 'justify-center',
                isActive
                  ? 'bg-brand/15 text-brand'
                  : 'text-muted hover:text-fg hover:bg-surface-2',
              )
            }
          >
            <NavIcon name={item.icon} />
            {!collapsed && <span className="truncate">{item.label}</span>}
          </NavLink>
        ))}
      </nav>
    </aside>
  );
}
