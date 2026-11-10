import { useAuth, useBranch } from '@/store/auth';

/** Who a printed bill is from: the business and branch. */
export function useBusiness() {
  const name = useAuth((s) => s.me?.organization.name ?? '');
  const branch = useBranch();
  return { name, branch: branch?.name ?? null, footer: null };
}
