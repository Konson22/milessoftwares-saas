import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

import { ChangeMode } from './components/change-mode';
import { Shell } from './components/shell';
import { Notice } from './components/ui';
import { useOutbox } from './offline/outbox';
import { BranchPage } from './pages/branch';
import { ChooseModePage } from './pages/choose-mode';
import { LoginPage as LocalLoginPage } from './pages/local/login';
import { SetUpPage } from './pages/local/set-up';
import { LoginPage } from './pages/login';
import { useAuth } from './store/auth';
import { useCart } from './store/cart';

/** Waits for what this PC saved (the mode, session, cart, outbox) before showing anything. */
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
  const mode = useAuth((s) => s.mode);
  const error = useAuth((s) => s.error);

  if (!hydrated || status === 'loading') {
    return (
      <div className="flex h-full items-center justify-center text-muted-foreground">
        <Loader2 className="animate-spin" />
      </div>
    );
  }
  if (status === 'chooseMode') return <ChooseModePage />;
  if (status === 'failed') {
    return (
      <div className="flex h-full flex-col items-center justify-center gap-4 p-8">
        <Notice tone="red">The pharmacy’s data could not be opened: {error}</Notice>
        <ChangeMode />
      </div>
    );
  }
  if (status === 'setUp') return <SetUpPage />;
  if (status === 'signedOut') return mode === 'offline' ? <LocalLoginPage /> : <LoginPage />;
  if (status === 'needsBranch') return <BranchPage />;
  return <Shell />;
}
