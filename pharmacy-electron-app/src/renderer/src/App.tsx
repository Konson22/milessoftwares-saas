import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Shell } from './components/shell';
import { useOutbox } from './offline/outbox';
import { BranchPage } from './pages/branch';
import { LoginPage } from './pages/login';
import { useAuth } from './store/auth';
import { useCart } from './store/cart';

/** Waits for what this PC saved (session, cart, outbox) before showing anything. */
function useHydrated() {
  const [ready, setReady] = useState(false);
  useEffect(() => {
    const persisted = (store: typeof useCart | typeof useOutbox) =>
      store.persist.hasHydrated() ? Promise.resolve() : new Promise<void>((resolve) => store.persist.onFinishHydration(() => resolve()));
    void Promise.all([useAuth.getState().hydrate(), persisted(useCart), persisted(useOutbox)]).then(() => setReady(true));
  }, []);
  return ready;
}

export function App() {
  const hydrated = useHydrated();
  const status = useAuth((s) => s.status);

  if (!hydrated || status === 'loading') {
    return (
      <div className="flex h-full items-center justify-center text-muted-foreground">
        <Loader2 className="animate-spin" />
      </div>
    );
  }
  if (status === 'signedOut') return <LoginPage />;
  if (status === 'needsBranch') return <BranchPage />;
  return <Shell />;
}
