import { Component, type ErrorInfo, type ReactNode } from 'react';
import { AlertTriangle } from 'lucide-react';

interface Props {
  children: ReactNode;
}

interface State {
  error: Error | null;
}

/**
 * Top-level boundary that catches render-time errors anywhere in the app and
 * shows a recoverable fallback instead of a blank white screen.
 */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // Surface to the console for diagnostics; no external telemetry by default.
    console.error('Unhandled UI error:', error, info.componentStack);
  }

  private reset = () => this.setState({ error: null });

  render(): ReactNode {
    const { error } = this.state;
    if (!error) return this.props.children;

    return (
      <div className="min-h-screen grid place-items-center bg-bg text-fg px-6">
        <div className="card max-w-md w-full p-8 text-center">
          <div className="mx-auto mb-4 grid place-items-center h-14 w-14 rounded-full bg-danger/15 text-danger">
            <AlertTriangle size={26} />
          </div>
          <h1 className="text-base font-semibold">Something went wrong</h1>
          <p className="text-xs text-muted mt-1.5 max-w-sm mx-auto break-words">
            The interface hit an unexpected error. You can try again, or reload the page.
          </p>
          {error.message && (
            <pre className="mt-4 max-h-32 overflow-auto rounded-md bg-surface-2 px-3 py-2 text-left text-2xs text-faint whitespace-pre-wrap break-words">
              {error.message}
            </pre>
          )}
          <div className="mt-6 flex items-center justify-center gap-2">
            <button
              type="button"
              onClick={this.reset}
              className="rounded-md border border-border bg-surface-2 px-3 h-9 text-sm font-medium hover:bg-elevated transition-colors"
            >
              Try again
            </button>
            <button
              type="button"
              onClick={() => window.location.reload()}
              className="rounded-md bg-brand px-3 h-9 text-sm font-medium text-brand-fg hover:opacity-90 transition-opacity"
            >
              Reload
            </button>
          </div>
        </div>
      </div>
    );
  }
}
