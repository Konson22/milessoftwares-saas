import { router } from 'expo-router';

import type { NoticeRoute } from '@/logbook/notices';
import { useLogbook } from '@/store/logbook';
import { toast } from '@/store/toast';

/**
 * Opens what a notice is about. The screens fetch the record again, so
 * the server decides whether the person may still see it.
 */
export async function openRoute(route: NoticeRoute | null): Promise<void> {
  if (!route) return;
  switch (route.screen) {
    case 'task':
      router.push({ pathname: '/task/[id]', params: { id: route.id } });
      return;
    case 'review':
      router.push({ pathname: '/review/[id]', params: { id: route.id } });
      return;
    case 'entry': {
      // An entry of the person's own: open it where the phone keeps it.
      const item = useLogbook.getState().items.find((i) => i.serverId === route.id);
      if (item) router.push({ pathname: '/entry/[id]', params: { id: item.id } });
      else router.push('/entries');
      return;
    }
    case 'record': {
      const { kit, start } = useLogbook.getState();
      const sheet = kit?.sheets.find((s) => s.id === route.sheet);
      if (!sheet) {
        toast('This log sheet is not on the phone. Connect, then open Sync and update the sheets.', 'error');
        router.push('/new');
        return;
      }
      try {
        const item = await start(sheet, { occurrenceId: route.occurrence });
        router.push({ pathname: '/entry/[id]', params: { id: item.id } });
      } catch (e) {
        toast(e instanceof Error ? e.message : 'Could not open the sheet.', 'error');
      }
    }
  }
}
