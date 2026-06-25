import { useState, type ReactNode } from 'react';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';
import { CommandPalette } from './CommandPalette';

export function AppShell({
  title,
  toolbar,
  children,
}: {
  title: string;
  toolbar?: ReactNode;
  children: ReactNode;
}) {
  const [collapsed, setCollapsed] = useState(() => window.innerWidth < 1024);

  return (
    <div className="h-full flex bg-bg">
      <CommandPalette />
      <Sidebar collapsed={collapsed} />
      <div className="flex-1 flex flex-col min-w-0">
        <Topbar title={title} onToggleSidebar={() => setCollapsed((c) => !c)}>
          {toolbar}
        </Topbar>
        <main className="flex-1 overflow-auto">
          <div className="mx-auto w-full max-w-[1600px] p-4 sm:p-6 animate-fade-in">{children}</div>
        </main>
      </div>
    </div>
  );
}
