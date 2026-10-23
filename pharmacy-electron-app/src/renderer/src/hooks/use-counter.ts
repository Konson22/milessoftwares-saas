import { useEffect, useState } from 'react';

import { errorMessage } from '@/lib/api';
import { useAuth } from '@/store/auth';

/**
 * The branch's counter and the member's shift, from the pharmacy or, offline,
 * from what it last said. Loaded once a branch; the sync engine refreshes them.
 */
export function useCounter() {
  const counter = useAuth((s) => s.counter);
  const till = useAuth((s) => s.till);
  const tillLoaded = useAuth((s) => s.tillLoaded);
  const staleSince = useAuth((s) => s.staleSince);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const { counter, tillLoaded, refreshCounter, refreshTill } = useAuth.getState();
    if (counter && tillLoaded) return;
    Promise.all([counter ? null : refreshCounter(), tillLoaded ? null : refreshTill()])
      .then(() => setError(null))
      .catch((e) => setError(errorMessage(e)));
  }, []);

  return { counter, till, tillLoaded, staleSince, error };
}
