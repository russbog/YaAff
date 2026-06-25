import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { HashRouter } from 'react-router-dom';
import App from './App';
import { ThemeProvider } from './providers/ThemeProvider';
import { ToastProvider } from './providers/ToastProvider';
import { ConfirmProvider } from './components/ui/ConfirmDialog';
import { BootstrapProvider } from './providers/BootstrapProvider';
import { RangeProvider } from './providers/RangeProvider';
import { ErrorBoundary } from './components/ui/ErrorBoundary';
import './styles/index.css';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      refetchOnWindowFocus: false,
      staleTime: 15_000,
    },
  },
});

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <ThemeProvider>
      <ErrorBoundary>
        <QueryClientProvider client={queryClient}>
          <ToastProvider>
            <ConfirmProvider>
              <BootstrapProvider>
                <RangeProvider>
                  <HashRouter>
                    <App />
                  </HashRouter>
                </RangeProvider>
              </BootstrapProvider>
            </ConfirmProvider>
          </ToastProvider>
        </QueryClientProvider>
      </ErrorBoundary>
    </ThemeProvider>
  </StrictMode>,
);
