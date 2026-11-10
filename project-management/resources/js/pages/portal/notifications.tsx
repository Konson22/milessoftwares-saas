import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { cn } from '@miles/ui/lib/utils';
import { formatDateTime } from '@/lib/status';
import { emailAlerts as saveEmailAlerts } from '@/routes/account';
import { open, readAll } from '@/routes/notifications';

type Notification = {
    id: string;
    kind: string;
    title: string;
    excerpt: string | null;
    at: string;
    read: boolean;
};

/**
 * What you should know about, newest first.
 */
export default function NotificationsPage({
    notifications,
    emailAlerts,
}: {
    notifications: Notification[];
    emailAlerts: boolean;
}) {
    const unread = notifications.some((item) => !item.read);

    return (
        <>
            <Head title="Notifications" />
            <PageHeader
                title="Notifications"
                description="Your tasks, approvals waiting for you, decisions on your requests, and what is late."
                actions={
                    unread && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={readAll()} as="button" preserveScroll>
                                Mark all as read
                            </Link>
                        </Button>
                    )
                }
            />

            <Panel>
                {notifications.length === 0 ? (
                    <PanelBody>
                        <p className="text-sm text-muted-foreground">
                            Nothing yet. You&rsquo;ll hear here when someone
                            mentions you, gives you a task or needs your
                            approval.
                        </p>
                    </PanelBody>
                ) : (
                    <ul className="divide-y">
                        {notifications.map((item) => (
                            <li key={item.id}>
                                <Link
                                    href={open(item.id)}
                                    as="button"
                                    className="flex w-full items-start gap-2 px-3 py-2 text-left text-sm hover:bg-muted/50"
                                >
                                    <span
                                        className={cn(
                                            'mt-1.5 size-2 shrink-0 rounded-full',
                                            item.read
                                                ? 'bg-transparent'
                                                : 'bg-primary',
                                        )}
                                        aria-hidden
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span
                                            className={cn(
                                                'block',
                                                !item.read && 'font-medium',
                                            )}
                                        >
                                            {item.title}
                                            {!item.read && (
                                                <span className="sr-only">
                                                    {' '}
                                                    (unread)
                                                </span>
                                            )}
                                        </span>
                                        {item.excerpt && (
                                            <span className="block truncate text-muted-foreground">
                                                {item.excerpt}
                                            </span>
                                        )}
                                        <span className="block text-xs text-muted-foreground">
                                            {formatDateTime(item.at)}
                                        </span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
                <p>
                    {emailAlerts
                        ? 'Alerts also come to you by email.'
                        : 'Alerts come here only, not by email.'}
                </p>
                <Button variant="outline" size="sm" asChild>
                    <Link
                        href={saveEmailAlerts()}
                        data={{ email_alerts: !emailAlerts }}
                        as="button"
                        preserveScroll
                    >
                        {emailAlerts
                            ? 'Stop email alerts'
                            : 'Send alerts by email'}
                    </Link>
                </Button>
            </div>
        </>
    );
}
