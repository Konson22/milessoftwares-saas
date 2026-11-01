import { PageView } from '@/components/pages/page-view';
import { useAccess } from '@/hooks/use-page';
import { allowed, navItem } from '@/lib/navigation';

/**
 * The Tables tab: the restaurant's "tables" page for those who set tables up
 * (managers); the floor, where tables are seated and served, for the rest
 * (waiters, cashiers).
 */
export default function TablesTab() {
  const shared = useAccess((s) => s.shared);
  const tables = navItem('tables');
  const path = tables && allowed(tables, shared) ? 'tables' : 'floor';
  return <PageView key={path} path={path} />;
}
