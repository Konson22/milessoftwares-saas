import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Notice } from './components/ui';
import { Shell } from './components/shell';
import { errorMessage } from './lib/api';
import { LoginPage } from './pages/login';
import { SetUpPage } from './pages/set-up';
import { useAuth } from './store/auth';
import { useCart } from './store/cart';

/** Waits for the pharmacy's state and this PC's cart before showing anything. */
function useHydrated() {
  const [ready, setReady] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    const cart = useCart.persist.hasHydrated() ? Promise.resolve() : new Promise<void>((resolve) => useCart.persist.onFinishHydration(() => resolve()));
    Promise.all([useAuth.getState().hydrate(), cart])
      .then(() => setReady(true))
      .catch((e) => setError(errorMessage(e)));
  }, []);
  return { ready, error };
}

export function App() {
  const { ready, error } = useHydrated();
  const status = useAuth((s) => s.status);

  if (error) {
    return (
      <div className="flex h-full items-center justify-center p-8">
        <Notice tone="red">The pharmacy’s data could not be opened: {error}</Notice>
      </div>
    );
  }
  if (!ready || status === 'loading') {
    return (
      <div className="flex h-full items-center justify-center text-muted-foreground">
        <Loader2 className="animate-spin" />
      </div>
    );
  }
  if (status === 'setUp') return <SetUpPage />;
  if (status === 'signedOut') return <LoginPage />;
  return <Shell />;
}
