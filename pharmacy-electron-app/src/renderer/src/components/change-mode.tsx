import { ArrowLeftRight } from 'lucide-react';

import { useAuth } from '@/store/auth';

/** Back to the choice of mode (signed out only): the other mode's data stays as it was. */
export function ChangeMode() {
  const chooseMode = useAuth((s) => s.chooseMode);
  const mode = useAuth((s) => s.mode);
  return (
    <p className="text-center text-xs text-muted-foreground">
      {mode === 'offline' ? 'This PC runs the pharmacy on its own. ' : 'This PC works with a Miles workspace. '}
      <button type="button" onClick={() => void chooseMode(null)} className="inline-flex items-center gap-1 font-medium text-primary hover:underline">
        <ArrowLeftRight size={12} /> Change
      </button>
    </p>
  );
}
