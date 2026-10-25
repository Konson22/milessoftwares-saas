import { Loader2 } from 'lucide-react';
import { useEffect } from 'react';

import { Shell } from './components/shell';
import { BranchPage } from './pages/branch';
import { LoginPage } from './pages/login';
import { useAuth } from './store/auth';

export function App() {
  const status = useAuth((s) => s.status);

  useEffect(() => {
    void useAuth.getState().hydrate();
  }, []);

  if (status === 'loading') {
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
