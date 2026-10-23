import { useEffect, useState } from 'react';

import { errorMessage } from '@/lib/api';
import { useAuth } from '@/store/auth';

/** The counter's rules and the member's shift, loaded once a sign-in. */
export function useCounter() {
  const counter = useAuth((s) => s.counter);
  const till = useAuth((s) => s.till);
  const tillLoaded = useAuth((s) => s.tillLoaded);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (counter && tillLoaded) return;
    const { refreshCounter, refreshTill } = useAuth.getState();
    Promise.all([counter ? null : refreshCounter(), tillLoaded ? null : refreshTill()])
      .then(() => setError(null))
      .catch((e) => setError(errorMessage(e)));
  }, [counter, tillLoaded]);

  return { counter, till, tillLoaded, error };
}
