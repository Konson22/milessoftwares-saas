import { Form, Head } from '@inertiajs/react';
import { SquareArrowOutUpRight } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { update } from '@/routes/branch/settings';

type Props = {
    details: { name: string; code: string; city: string | null };
    settings: {
        receipt_header: string | null;
        receipt_footer: string | null;
        phone: string | null;
        default_float: string | null;
        slow_ticket_minutes: number | null;
    };
    defaultSlowMinutes: number;
    structureUrl: string | null;
};

function FieldError({ message }: { message?: string }) {
    return message ? (
        <p className="text-sm text-destructive">{message}</p>
    ) : null;
}

/**
 * How this branch runs the restaurant: what its receipts say, the cash its
 * tills usually start with, and when the kitchen calls a ticket late. The
 * branch's name, address and whether it is open are kept in the workspace.
 */
export default function BranchSettings({
    details,
    settings,
    defaultSlowMinutes,
    structureUrl,
}: Props) {
    return (
        <>
            <Head title="Branch settings" />
            <PageHeader
                title="Branch settings"
                description={`How ${details.name} runs the restaurant.`}
            />

            <div className="grid gap-3 lg:grid-cols-3">
                <Panel className="lg:col-span-2">
                    <Form
                        {...update.form()}
                        options={{ preserveScroll: true }}
                        className="contents"
                    >
                        {({ errors, processing }) => (
                            <>
                                <PanelHeader
                                    title="Receipts"
                                    description="Printed on every receipt from this branch."
                                />
                                <PanelBody className="grid gap-4">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="receipt_header">
                                            Line at the top
                                        </Label>
                                        <Input
                                            id="receipt_header"
                                            name="receipt_header"
                                            defaultValue={
                                                settings.receipt_header ?? ''
                                            }
                                            placeholder="For example: Hai Malakal, opposite the market"
                                            maxLength={255}
                                        />
                                        <FieldError
                                            message={errors.receipt_header}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="phone">Phone</Label>
                                        <Input
                                            id="phone"
                                            name="phone"
                                            defaultValue={settings.phone ?? ''}
                                            className="w-60"
                                            maxLength={64}
                                        />
                                        <FieldError message={errors.phone} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="receipt_footer">
                                            Line at the bottom
                                        </Label>
                                        <Input
                                            id="receipt_footer"
                                            name="receipt_footer"
                                            defaultValue={
                                                settings.receipt_footer ?? ''
                                            }
                                            placeholder="For example: Thank you. Open every day, 7am to 11pm."
                                            maxLength={255}
                                        />
                                        <FieldError
                                            message={errors.receipt_footer}
                                        />
                                    </div>
                                </PanelBody>

                                <PanelHeader title="Service" />
                                <PanelBody className="grid gap-4">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="default_float">
                                            Usual starting cash in a till
                                        </Label>
                                        <Input
                                            id="default_float"
                                            name="default_float"
                                            inputMode="decimal"
                                            defaultValue={
                                                settings.default_float ?? ''
                                            }
                                            placeholder="0.00"
                                            className="w-44"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Filled in for cashiers when they
                                            start a shift. They can change it.
                                        </p>
                                        <FieldError
                                            message={errors.default_float}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="slow_ticket_minutes">
                                            A kitchen ticket is late after
                                            (minutes)
                                        </Label>
                                        <Input
                                            id="slow_ticket_minutes"
                                            name="slow_ticket_minutes"
                                            type="number"
                                            min={5}
                                            max={240}
                                            defaultValue={
                                                settings.slow_ticket_minutes ??
                                                ''
                                            }
                                            placeholder={String(
                                                defaultSlowMinutes,
                                            )}
                                            className="w-32"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Late tickets are marked on the
                                            kitchen screen and raised on the
                                            management dashboard. Empty means{' '}
                                            {defaultSlowMinutes}.
                                        </p>
                                        <FieldError
                                            message={errors.slow_ticket_minutes}
                                        />
                                    </div>
                                    <div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Save settings
                                        </Button>
                                    </div>
                                </PanelBody>
                            </>
                        )}
                    </Form>
                </Panel>

                <Panel>
                    <PanelHeader
                        title="The branch"
                        description="Kept in the workspace, for every app."
                    />
                    <PanelBody>
                        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
                            <dt className="text-muted-foreground">Name</dt>
                            <dd>{details.name}</dd>
                            <dt className="text-muted-foreground">Code</dt>
                            <dd>{details.code}</dd>
                            <dt className="text-muted-foreground">City</dt>
                            <dd>{details.city ?? '—'}</dd>
                        </dl>
                        {structureUrl && (
                            <Button
                                asChild
                                size="sm"
                                variant="outline"
                                className="mt-4"
                            >
                                <a
                                    href={structureUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Change in the workspace
                                    <SquareArrowOutUpRight
                                        className="size-3.5"
                                        aria-hidden
                                    />
                                </a>
                            </Button>
                        )}
                    </PanelBody>
                </Panel>
            </div>
        </>
    );
}
