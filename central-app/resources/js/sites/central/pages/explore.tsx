import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Puzzle } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@/shared/components/ui/button';
import { dashboard, explore } from '@/routes';
import { create as createOrganization } from '@/routes/organizations';
import { appIcon } from '@/shared/lib/app-icons';

type Application = {
    code: string;
    name: string;
    tagline: string | null;
    comingSoon: boolean;
};

type Props = {
    trialDays: number;
    applications: Application[];
};

/**
 * Every ERP application on the platform, as a way in: each released one
 * opens a new business with its free trial already chosen.
 */
export default function Explore({ trialDays, applications }: Props) {
    const available = applications.filter((app) => !app.comingSoon);
    const comingSoon = applications.filter((app) => app.comingSoon);

    return (
        <>
            <Head title="Explore the ERP" />

            <PageHeader
                title="Explore the Miles Softwares ERP"
                description={`Start a business on any of our applications, free for ${trialDays} days. No payment details needed.`}
            />

            <div className="flex flex-col gap-3">
                {applications.length === 0 && (
                    <Panel>
                        <EmptyState
                            icon={Puzzle}
                            title="Nothing to explore yet"
                        >
                            Applications will appear here as they are released.
                        </EmptyState>
                    </Panel>
                )}

                {available.length > 0 && (
                    <Panel aria-labelledby="available">
                        <PanelHeader
                            title={<span id="available">Available now</span>}
                            description="Try any of these free in a new business."
                        />
                        <ApplicationGrid applications={available} />
                    </Panel>
                )}

                {comingSoon.length > 0 && (
                    <Panel aria-labelledby="coming-soon">
                        <PanelHeader
                            title={<span id="coming-soon">Coming soon</span>}
                            description="On the way to the Miles Softwares ERP."
                        />
                        <ApplicationGrid applications={comingSoon} />
                    </Panel>
                )}
            </div>
        </>
    );
}

function ApplicationGrid({ applications }: { applications: Application[] }) {
    return (
        <ul className="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3">
            {applications.map((application) => {
                const Icon = appIcon(application.code);

                return (
                    <li
                        key={application.code}
                        className="flex h-full flex-col gap-3 rounded-lg border p-4"
                    >
                        <span className="flex items-start gap-3">
                            <span className="grid size-10 shrink-0 place-items-center rounded-md bg-primary/10 text-primary">
                                <Icon className="size-5" aria-hidden />
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="flex flex-wrap items-center gap-1.5">
                                    <span className="font-medium">
                                        {application.name}
                                    </span>
                                    {application.comingSoon && (
                                        <StatusBadge tone="neutral">
                                            Coming soon
                                        </StatusBadge>
                                    )}
                                </span>
                                {application.tagline && (
                                    <span className="mt-0.5 line-clamp-3 block text-sm text-muted-foreground">
                                        {application.tagline}
                                    </span>
                                )}
                            </span>
                        </span>
                        {!application.comingSoon && (
                            <Button
                                asChild
                                size="sm"
                                className="mt-auto self-start"
                            >
                                <Link
                                    href={createOrganization({
                                        query: { app: application.code },
                                    })}
                                >
                                    Start free trial <ArrowRight />
                                </Link>
                            </Button>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

Explore.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Explore the ERP', href: explore() },
    ],
};
