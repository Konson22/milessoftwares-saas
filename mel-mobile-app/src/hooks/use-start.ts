import { router } from 'expo-router';
import { useState } from 'react';

import type { Assignment } from '@/collect/types';
import { errorMessage } from '@/lib/api/client';
import { useCollect } from '@/store/collect';
import { toast } from '@/store/toast';

/** Starts a new record on an assignment's current form version and opens it. */
export function useStartRecord() {
  const start = useCollect((s) => s.start);
  const [busy, setBusy] = useState<string | null>(null);

  async function begin(assignment: Assignment) {
    if (busy) return;
    setBusy(assignment.id);
    try {
      const item = await start(assignment);
      router.push({ pathname: '/fill/[id]', params: { id: item.id } });
    } catch (error) {
      toast(errorMessage(error), 'error');
    } finally {
      setBusy(null);
    }
  }

  return { begin, busy };
}
