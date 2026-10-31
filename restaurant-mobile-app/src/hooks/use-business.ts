import { settings } from '@/local/db/settings';
import { useAuth, useBranch } from '@/store/auth';

/** Who a printed bill is from: the business and branch, and (offline) the footer set for bills. */
export function useBusiness() {
  const name = useAuth((s) => s.me?.organization.name ?? '');
  const offline = useAuth((s) => s.mode === 'offline');
  const branch = useBranch();
  return { name, branch: branch?.name ?? null, footer: offline ? settings().bill_footer : null };
}
