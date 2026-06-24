import { Link } from 'react-router-dom';
import { AppShell } from '@/components/layout/AppShell';
import { EmptyState } from '@/components/ui/States';
import { Button } from '@/components/ui/Button';

export function NotFoundPage() {
  return (
    <AppShell title="Not found">
      <EmptyState
        title="Page not found"
        description="The screen you are looking for does not exist."
        action={
          <Link to="/">
            <Button variant="primary">Back to start</Button>
          </Link>
        }
      />
    </AppShell>
  );
}
