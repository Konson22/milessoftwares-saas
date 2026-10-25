import { ChevronLeft, ChevronRight } from 'lucide-react';

import { Button } from '@/components/ui';
import { isoDate, prettyDate, shiftDate } from '@/lib/format';
import { withQuery } from '@/lib/pages';
import { useUi } from '@/store/ui';

/** The day a list shows: earlier, later (up to today), as ?day= on the page. */
export function DayPicker({ section, day }: { section: string; day: string }) {
  const open = useUi((s) => s.open);
  const go = (next: string) => open(withQuery(section, { day: next === isoDate() ? null : next }));

  return (
    <div className="flex items-center gap-1">
      <Button variant="secondary" size="sm" onClick={() => go(shiftDate(day, -1))} aria-label="Day before">
        <ChevronLeft size={15} />
      </Button>
      <span className="w-28 text-center text-sm font-medium">{prettyDate(day)}</span>
      <Button variant="secondary" size="sm" disabled={day >= isoDate()} onClick={() => go(shiftDate(day, 1))} aria-label="Day after">
        <ChevronRight size={15} />
      </Button>
    </div>
  );
}
