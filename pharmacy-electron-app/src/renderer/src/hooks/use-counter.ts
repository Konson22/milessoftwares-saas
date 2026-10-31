import { useEffect, useState } from 'react';

import { errorMessage } from '@/lib/api';
import { useAuth } from '@/store/auth';

/**
 * The branch's counter and the member's shift, from the pharmacy or, offline,
 * from what it last said. Loaded once a branch, and again when cleared (the
 * member's details changed); the sync engine refreshes them.
 */
export function useCounter() {
  const counter = useAuth((s) => s.counter);
  const till = useAuth((s) => s.till);
  const tillLoaded = useAuth((s) => s.tillLoaded);
  const staleSince = useAuth((s) => s.staleSince);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (counter && tillLoaded) return;
    const { refreshCounter, refreshTill } = useAuth.getState();
    Promise.all([counter ? null : refreshCounter(), tillLoaded ? null : refreshTill()])
      .then(() => setError(null))
      .catch((e) => setError(errorMessage(e)));
  }, [counter, tillLoaded]);

  return { counter, till, tillLoaded, staleSince, error };
}
