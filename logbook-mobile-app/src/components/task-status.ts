type Tone = 'neutral' | 'green' | 'amber' | 'red' | 'blue';

const STATUS: Record<string, [string, Tone]> = {
  pending: ['To do', 'neutral'],
  in_progress: ['In progress', 'blue'],
  awaiting_verification: ['Awaiting verification', 'blue'],
  returned: ['Returned', 'amber'],
  completed: ['Completed', 'green'],
  cancelled: ['Cancelled', 'neutral'],
};

/** A task's status as a badge: overdue open work shows as overdue. */
export function taskStatus(status: string, overdue: boolean): [string, Tone] {
  if (overdue && ['pending', 'in_progress', 'returned'].includes(status)) return ['Overdue', 'red'];
  return STATUS[status] ?? [status, 'neutral'];
}
