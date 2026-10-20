import { usePage } from '@inertiajs/react';
import { ArrowLeft, Landmark } from 'lucide-react';
import { useCallback } from 'react';
import type { PropsWithChildren } from 'react';
import { AppShell } from '@miles/ui/shell/app-shell';
import { activeItem, crumbsFor } from '@miles/ui/shell/navigation';
import { QuickSearch } from '@miles/ui/shell/quick-search';
import type { QuickSearchTarget } from '@miles/ui/shell/quick-search';
import { ShellSidebar } from '@miles/ui/shell/sidebar';
import { UserMenu } from '@miles/ui/shell/user-menu';
import { useCan } from '@/lib/access';
import { visibleNavigation } from '@/lib/navigation';
import { home } from '@/routes';
import { index as bills } from '@/routes/bills';
import { index as invoices } from '@/routes/invoices';
import { index as journals } from '@/routes/journals';
import { logout } from '@/routes/platform';

/**
 * Finance in the shared shell: its sections, and searches of journals,
 * bills and invoices.
 */
export default function ProductLayout({ children }: PropsWithChildren) {
    const { url, props } = usePage();
    const { flash, product, workspace, user, multicurrency, fixedAssets } =
        props;
    const can = useCan();
    const groups = visibleNavigation(can, { multicurrency, fixedAssets });
    const active = activeItem(groups, url, home());

    const searches = useCallback(
        (term: string): QuickSearchTarget[] =>
            [
                {
                    key: 'journals',
                    label: `Journals matching “${term}”`,
                    permission: 'finance.journals.view',
                    url: journals.url({ query: { search: term } }),
                },
                {
                    key: 'bills',
                    label: `Bills matching “${term}”`,
                    permission: 'finance.payables.view',
                    url: bills.url({ query: { search: term, status: 'all' } }),
                },
                {
                    key: 'invoices',
                    label: `Invoices matching “${term}”`,
                    permission: 'finance.receivables.view',
                    url: invoices.url({
                        query: { search: term, status: 'all' },
                    }),
                },
            ].filter((target) => can(target.permission)),
        [can],
    );

    return (
        <AppShell
            flash={flash.success}
            crumbs={crumbsFor(
                { label: product.name, href: home().url },
                active,
                home(),
            )}
            actions={
                <>
                    <QuickSearch
                        groups={groups}
                        searches={searches}
                        placeholder="Go to a section, or search journals, bills, invoices…"
                    />
                    {user && (
                        <UserMenu
                            user={user}
                            subtitle={workspace?.name}
                            links={
                                workspace
                                    ? [
                                          {
                                              label: `${workspace.name} home`,
                                              href: workspace.url,
                                              icon: ArrowLeft,
                                              external: true,
                                          },
                                      ]
                                    : []
                            }
                            signOut={{
                                label: `Sign out of ${product.name}`,
                                ...logout.form(),
                            }}
                        />
                    )}
                </>
            }
            sidebar={(onNavigate) => (
                <ShellSidebar
                    label={product.name}
                    back={
                        workspace
                            ? {
                                  label: `${workspace.name} home`,
                                  href: workspace.url,
                              }
                            : undefined
                    }
                    brand={{
                        title: product.name,
                        subtitle: workspace?.name,
                        icon: Landmark,
                        href: home(),
                    }}
                    groups={groups}
                    active={active?.item ?? null}
                    onNavigate={onNavigate}
                />
            )}
        >
            {children}
        </AppShell>
    );
}
