import { Form, Head } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { ApprovalBands } from '@/components/approval-bands';
import type { ApprovalBand } from '@/components/approval-bands';
import { Field, invalid } from '@/components/field';
import {
    financeLimits as saveFinanceLimits,
    funding,
    health,
    modules as saveModule,
} from '@/routes/admin/settings';

type Settings = {
    name: string;
    slug: string;
    currency: string;
    timezone: string;
    modules: {
        key: string;
        name: string;
        description: string;
        enabled: boolean;
    }[];
    funding: { enabled: boolean; canTurnOff: boolean };
    financeLimits: {
        currency: string;
        pettyCashMax: string | null;
        advanceMax: string | null;
        advanceRetireDays: number | null;
    } | null;
    health: { scheduleSlack: number; budgetSlack: number };
};

/**
 * The parts of the product this organization uses.
 */
function ModulesPanel({ modules }: { modules: Settings['modules'] }) {
    return (
        <Panel className="mt-4">
            <PanelHeader
                title="Parts of the product"
                description="Turning one off hides it for everyone here. Nothing in it is deleted, and turning it on again brings it all back."
            />
            <ul className="divide-y">
                {modules.map((module) => (
                    <li key={module.key} className="px-4 py-3">
                        <Form
                            {...saveModule.form(module.key)}
                            options={{ preserveScroll: true }}
                            className="flex flex-wrap items-center justify-between gap-3"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="enabled"
                                        value={module.enabled ? '0' : '1'}
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="font-medium">
                                            {module.name}
                                        </span>
                                        <span className="block text-sm text-muted-foreground">
                                            {module.description}
                                        </span>
                                        {errors.module && (
                                            <span className="block text-sm text-destructive">
                                                {errors.module}
                                            </span>
                                        )}
                                    </span>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        {module.enabled
                                            ? 'Turn off'
                                            : 'Turn on'}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

/**
 * Whether this organization also funds others: it adds the funding sections to the menu.
 */
function FundingPanel({
    enabled,
    canTurnOff,
}: {
    enabled: boolean;
    canTurnOff: boolean;
}) {
    return (
        <Panel className="mt-4">
            <PanelHeader title="Funding other organizations" />
            <PanelBody>
                <Form
                    {...funding.form()}
                    options={{ preserveScroll: true }}
                    className="grid gap-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="funds"
                                value={enabled ? '0' : '1'}
                            />
                            <p className="text-sm text-muted-foreground">
                                {enabled
                                    ? 'This organization funds others: Funding others and Marketplace in the menu are where it invites the organizations it funds and follows what they share.'
                                    : 'Turn this on to invite the organizations you fund and follow what they share. Your own work and records do not change.'}
                            </p>
                            {enabled && !canTurnOff && (
                                <p className="text-sm text-muted-foreground">
                                    To turn it off, first remove the
                                    organizations you fund.
                                </p>
                            )}
                            {errors.funding && (
                                <p className="text-sm text-destructive">
                                    {errors.funding}
                                </p>
                            )}
                            <div>
                                <Button
                                    type="submit"
                                    variant={enabled ? 'outline' : 'default'}
                                    disabled={
                                        processing || (enabled && !canTurnOff)
                                    }
                                >
                                    {enabled
                                        ? 'Turn off funding'
                                        : 'Turn on funding'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}

/**
 * Administration → Settings: the organization's name and defaults.
 */
export default function OrganizationSettings({
    settings,
    approvalPolicies,
    maxApprovers,
}: {
    settings: Settings;
    approvalPolicies: ApprovalBand[];
    maxApprovers: number;
}) {
    return (
        <>
            <Head title="Organization settings" />
            <PageHeader title="Organization settings" />

            <Panel>
                <PanelHeader
                    title={settings.name}
                    description="The name, currency and time zone are the workspace's, changed in its settings on the platform."
                />
                <PanelBody>
                    <dl className="grid gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-muted-foreground">Workspace</dt>
                            <dd>{settings.slug}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                Default currency
                            </dt>
                            <dd>{settings.currency}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Time zone</dt>
                            <dd>{settings.timezone}</dd>
                        </div>
                    </dl>
                </PanelBody>
            </Panel>

            <ModulesPanel modules={settings.modules} />

            <FundingPanel {...settings.funding} />

            <HealthThresholds {...settings.health} />
            {settings.financeLimits && (
                <FinanceLimits {...settings.financeLimits} />
            )}
            <ApprovalBands
                bands={approvalPolicies}
                currency={settings.currency}
                maxApprovers={maxApprovers}
            />
        </>
    );
}

/**
 * What the organization allows from petty cash. There is no default: until
 * it is set, nothing is paid.
 */
function FinanceLimits({
    currency,
    pettyCashMax,
    advanceMax,
    advanceRetireDays,
}: {
    currency: string;
    pettyCashMax: string | null;
    advanceMax: string | null;
    advanceRetireDays: number | null;
}) {
    return (
        <Panel className="mt-4">
            <PanelHeader
                title="Finance limits"
                description="Your organization's own policy. Until a limit is set, nothing can be paid under it."
            />
            <PanelBody>
                <Form
                    {...saveFinanceLimits.form()}
                    options={{ preserveScroll: true }}
                    className="grid gap-4 sm:grid-cols-2"
                >
                    {({ errors, processing }) => (
                        <>
                            <Field
                                id="petty_cash_max_payment"
                                label={`Largest petty cash payment (${currency})`}
                                error={errors.petty_cash_max_payment}
                            >
                                <Input
                                    id="petty_cash_max_payment"
                                    name="petty_cash_max_payment"
                                    inputMode="decimal"
                                    defaultValue={pettyCashMax ?? ''}
                                    {...invalid(
                                        'petty_cash_max_payment',
                                        errors.petty_cash_max_payment,
                                    )}
                                />
                            </Field>
                            <Field
                                id="advance_max"
                                label={`Largest staff advance (${currency})`}
                                error={errors.advance_max}
                            >
                                <Input
                                    id="advance_max"
                                    name="advance_max"
                                    inputMode="decimal"
                                    defaultValue={advanceMax ?? ''}
                                    {...invalid(
                                        'advance_max',
                                        errors.advance_max,
                                    )}
                                />
                            </Field>
                            <Field
                                id="advance_retire_days"
                                label="Days allowed to account for an advance"
                                error={errors.advance_retire_days}
                            >
                                <Input
                                    id="advance_retire_days"
                                    name="advance_retire_days"
                                    type="number"
                                    min={1}
                                    max={365}
                                    defaultValue={advanceRetireDays ?? ''}
                                    {...invalid(
                                        'advance_retire_days',
                                        errors.advance_retire_days,
                                    )}
                                />
                            </Field>
                            <div className="sm:col-span-2">
                                <Button type="submit" disabled={processing}>
                                    Save limits
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}

/**
 * How far a project may slip before it shows "at risk" on dashboards.
 */
function HealthThresholds({
    scheduleSlack,
    budgetSlack,
}: {
    scheduleSlack: number;
    budgetSlack: number;
}) {
    return (
        <Panel className="mt-4">
            <PanelHeader
                title="Project health"
                description="A project is at risk when it slips further than this. Past its due date or over budget, it is off track whatever these say."
            />
            <PanelBody>
                <Form
                    {...health.form()}
                    options={{ preserveScroll: true }}
                    className="grid gap-4 sm:grid-cols-2"
                >
                    {({ errors, processing }) => (
                        <>
                            <Field
                                id="health_schedule_slack"
                                label="Schedule slack (points)"
                                error={errors.health_schedule_slack}
                                hint="How far progress may trail the share of time gone. 20 means 40% done with 60% of the time gone is still on track."
                            >
                                <Input
                                    id="health_schedule_slack"
                                    name="health_schedule_slack"
                                    type="number"
                                    min={0}
                                    max={100}
                                    required
                                    defaultValue={scheduleSlack}
                                    {...invalid(
                                        'health_schedule_slack',
                                        errors.health_schedule_slack,
                                    )}
                                />
                            </Field>
                            <Field
                                id="health_budget_slack"
                                label="Budget slack (points)"
                                error={errors.health_budget_slack}
                                hint="How far the share of budget spent may run ahead of progress."
                            >
                                <Input
                                    id="health_budget_slack"
                                    name="health_budget_slack"
                                    type="number"
                                    min={0}
                                    max={100}
                                    required
                                    defaultValue={budgetSlack}
                                    {...invalid(
                                        'health_budget_slack',
                                        errors.health_budget_slack,
                                    )}
                                />
                            </Field>
                            <div className="sm:col-span-2">
                                <Button type="submit" disabled={processing}>
                                    Save thresholds
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}
