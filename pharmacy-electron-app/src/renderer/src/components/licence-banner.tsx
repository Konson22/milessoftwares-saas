import { KeyRound } from 'lucide-react';
import { useEffect } from 'react';

import { isoDate, prettyDate } from '@/lib/format';
import { useAuth } from '@/store/auth';
import { useLicence } from '@/store/licence';
import { useUi } from '@/store/ui';

import { cn } from './ui';

/** Days left to show the trial's end from. */
const WARN_DAYS = 14;

/**
 * Offline: the trial's last two weeks, and a PC that may no longer sell.
 * A manager goes straight to Settings, Activation; anyone else is told whom to ask.
 */
export function LicenceBanner() {
  const state = useLicence((s) => s.state);
  const load = useLicence((s) => s.load);
  const page = useUi((s) => s.page);
  const open = useUi((s) => s.open);
  const manager = useAuth((s) => s.me?.user.role === 'manager');

  // Looked at again on each page: a trial ends while the app is open.
  useEffect(() => {
    void load();
  }, [load, page]);

  if (!state) return null;
  let message: string | null = null;
  let locked = true;
  if (state.status === 'trial') {
    locked = false;
    if (state.daysLeft <= WARN_DAYS) message = `The free trial ends ${prettyDate(isoDate(new Date(state.endsAt)))}: ${state.daysLeft} day${state.daysLeft === 1 ? '' : 's'} left.`;
  } else if (state.status === 'expired') {
    message = `The ${state.what === 'trial' ? 'free trial' : 'licence'} has ended. Selling and changes are paused; everything stays readable.`;
  } else if (state.status === 'invalid') {
    message = 'The activation code is for another PC or pharmacy. Selling and changes are paused.';
  } else if (state.status === 'clock') {
    message = 'This PC’s clock is behind the last time the app was used. Correct the date and time to keep selling.';
  }
  if (!message) return null;

  return (
    <div className={cn('flex items-center gap-3 border-b px-5 py-2 text-sm', locked ? 'border-red-200 bg-red-50 text-red-800' : 'border-amber-200 bg-amber-50 text-amber-900')}>
      <KeyRound size={15} className="shrink-0" />
      <span className="min-w-0 flex-1">{message}</span>
      {state.status === 'clock' ? null : manager ? (
        <button type="button" onClick={() => open('settings?tab=activation')} className="shrink-0 font-medium underline">
          Enter an activation code
        </button>
      ) : (
        <span className="shrink-0 text-xs">Ask the manager to activate this PC.</span>
      )}
    </div>
  );
}
