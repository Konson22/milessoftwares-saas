import { Head, Link, router } from '@inertiajs/react';
import { Badge } from '@/shared/components/ui/badge';
import { Button } from '@/shared/components/ui/button';

type Lead = {
    id: string;
    name: string;
    email: string;
    company: string | null;
    phone: string | null;
    message: string | null;
    status: 'new' | 'in_progress' | 'closed' | 'spam';
    receivedAt: string | null;
    product?: string | null;
    preferredContactTime?: string | null;
    subject?: string | null;
};

type Props = {
    status: 'open' | 'closed' | 'spam';
    demoRequests: Lead[];
    contactMessages: Lead[];
};

const tabs = [
    { key: 'open', label: 'Open' },
    { key: 'closed', label: 'Closed' },
    { key: 'spam', label: 'Spam' },
] as const;

export default function Leads({
    status,
    demoRequests,
    contactMessages,
}: Props) {
    return (
        <>
            <Head title="Leads" />

            <h1 className="text-2xl font-semibold">Leads</h1>
            <nav className="mt-4 flex gap-2" aria-label="Lead status">
                {tabs.map((tab) => (
                    <Link
                        key={tab.key}
                        href={`/leads?status=${tab.key}`}
                        className={`rounded-md px-3 py-1.5 text-sm ${status === tab.key ? 'bg-muted font-medium' : 'hover:bg-muted/50'}`}
                        aria-current={status === tab.key ? 'page' : undefined}
                    >
                        {tab.label}
                    </Link>
                ))}
            </nav>

            <div className="mt-4 grid gap-5 xl:grid-cols-2">
                <LeadList
                    title="Demo requests"
                    type="demo"
                    leads={demoRequests}
                />
                <LeadList
                    title="Contact messages"
                    type="contact"
                    leads={contactMessages}
                />
            </div>
        </>
    );
}

function LeadList({
    title,
    type,
    leads,
}: {
    title: string;
    type: 'demo' | 'contact';
    leads: Lead[];
}) {
    const setStatus = (lead: Lead, status: Lead['status']) =>
        router.put(
            `/leads/${type}/${lead.id}`,
            { status },
            { preserveScroll: true },
        );

    return (
        <section>
            <h2 className="text-lg font-medium">{title}</h2>
            {leads.length === 0 ? (
                <p className="mt-3 text-sm text-muted-foreground">None.</p>
            ) : (
                <ul className="mt-3 space-y-3">
                    {leads.map((lead) => (
                        <li
                            key={lead.id}
                            className="rounded-lg border p-4 text-sm"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">{lead.name}</span>
                                <a
                                    href={`mailto:${lead.email}`}
                                    className="text-primary hover:underline"
                                >
                                    {lead.email}
                                </a>
                                <Badge variant="outline">
                                    {lead.status.replace('_', ' ')}
                                </Badge>
                            </div>
                            <p className="mt-1 text-muted-foreground">
                                {[
                                    lead.company,
                                    lead.phone,
                                    lead.product,
                                    lead.subject,
                                    lead.preferredContactTime,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                                {lead.receivedAt &&
                                    ` · ${new Date(lead.receivedAt).toLocaleString()}`}
                            </p>
                            {lead.message && (
                                <p className="mt-2 whitespace-pre-line">
                                    {lead.message}
                                </p>
                            )}
                            <div className="mt-3 flex flex-wrap gap-2">
                                {lead.status !== 'in_progress' &&
                                    lead.status !== 'spam' && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setStatus(lead, 'in_progress')
                                            }
                                        >
                                            Working on it
                                        </Button>
                                    )}
                                {lead.status !== 'closed' &&
                                    lead.status !== 'spam' && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setStatus(lead, 'closed')
                                            }
                                        >
                                            Close
                                        </Button>
                                    )}
                                {lead.status === 'spam' ? (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setStatus(lead, 'new')}
                                    >
                                        Not spam
                                    </Button>
                                ) : (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => setStatus(lead, 'spam')}
                                    >
                                        Mark as spam
                                    </Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
