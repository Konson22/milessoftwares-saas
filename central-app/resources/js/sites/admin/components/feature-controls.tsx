import { Form } from '@inertiajs/react';
import InputError from '@/shared/components/input-error';
import { Badge } from '@/shared/components/ui/badge';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';

export type OrganizationFeature = {
    key: string;
    name: string;
    description: string | null;
    application: string | null;
    declared: boolean;
    limitUnit: string | null;
    granted: boolean;
    source: 'denied' | 'manual' | 'subscription' | 'trial' | null;
    limit: number | null;
    override: {
        id: string;
        effect: 'grant' | 'deny';
        reason: string | null;
        by: string | null;
        validUntil: string | null;
    } | null;
};

type Props = {
    base: string;
    features: OrganizationFeature[];
    canManage: boolean;
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm';

const sourceLabel: Record<string, string> = {
    denied: 'denied by staff',
    manual: 'granted by staff',
    subscription: 'from the plan',
    trial: 'from the trial',
};

/**
 * Plan features of the applications this organization uses, as products
 * see them. Staff grant or deny one regardless of plans: a denial wins
 * over a grant, a grant over the plan. Removing the override returns the
 * feature to what the plans say.
 */
export default function FeatureControls({ base, features, canManage }: Props) {
    if (features.length === 0) {
        return (
            <div className="space-y-2">
                <h2 className="text-lg font-medium">Features</h2>
                <p className="text-sm text-muted-foreground">
                    None of this organization&apos;s applications declare
                    features.
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-3">
            <h2 className="text-lg font-medium">Features</h2>
            <ul className="divide-y rounded-lg border text-sm">
                {features.map((feature) => (
                    <li key={feature.key} className="space-y-2 p-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">{feature.name}</span>
                            <code className="text-xs text-muted-foreground">
                                {feature.key}
                            </code>
                            <Badge
                                variant={
                                    feature.granted ? 'secondary' : 'outline'
                                }
                            >
                                {feature.granted ? 'on' : 'off'}
                            </Badge>
                            {feature.source && (
                                <span className="text-muted-foreground">
                                    {sourceLabel[feature.source]}
                                    {feature.granted &&
                                        feature.limit !== null &&
                                        ` · ${feature.limit} ${feature.limitUnit ?? ''}`}
                                </span>
                            )}
                            {!feature.declared && (
                                <Badge variant="outline">
                                    not in the catalog
                                </Badge>
                            )}
                        </div>
                        {feature.override && (
                            <p className="text-muted-foreground">
                                {feature.override.reason}
                                {feature.override.by &&
                                    ` · ${feature.override.by}`}
                                {feature.override.validUntil &&
                                    ` · until ${feature.override.validUntil.slice(0, 10)}`}
                            </p>
                        )}
                        {canManage && feature.declared && (
                            <details>
                                <summary className="cursor-pointer text-muted-foreground">
                                    Change
                                </summary>
                                <OverrideForm base={base} feature={feature} />
                                {feature.override && (
                                    <RemoveOverride
                                        base={base}
                                        id={feature.override.id}
                                    />
                                )}
                            </details>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function OverrideForm({
    base,
    feature,
}: {
    base: string;
    feature: OrganizationFeature;
}) {
    const id = (field: string) => `${feature.key}-${field}`;

    return (
        <Form
            action={`${base}/features`}
            method="post"
            options={{ preserveScroll: true }}
            resetOnSuccess
            className="mt-2 space-y-2"
        >
            {({ errors, processing }) => (
                <>
                    <input
                        type="hidden"
                        name="feature_key"
                        value={feature.key}
                    />
                    <div className="grid grid-cols-3 gap-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor={id('effect')}>Override</Label>
                            <select
                                id={id('effect')}
                                name="effect"
                                className={selectClass}
                                defaultValue={
                                    feature.granted ? 'deny' : 'grant'
                                }
                            >
                                <option value="grant">Grant</option>
                                <option value="deny">Deny</option>
                            </select>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={id('until')}>
                                Until (optional)
                            </Label>
                            <Input
                                id={id('until')}
                                name="valid_until"
                                type="date"
                            />
                            <InputError message={errors.valid_until} />
                        </div>
                        {feature.limitUnit && (
                            <div className="grid gap-1.5">
                                <Label htmlFor={id('limit')}>
                                    Limit ({feature.limitUnit})
                                </Label>
                                <Input
                                    id={id('limit')}
                                    name="limit"
                                    type="number"
                                    min={0}
                                    placeholder="No limit"
                                />
                                <InputError message={errors.limit} />
                            </div>
                        )}
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor={id('reason')}>Reason</Label>
                        <Input id={id('reason')} name="reason" required />
                        <InputError
                            message={errors.reason ?? errors.feature_key}
                        />
                    </div>
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        disabled={processing}
                    >
                        Save override
                    </Button>
                </>
            )}
        </Form>
    );
}

function RemoveOverride({ base, id }: { base: string; id: string }) {
    return (
        <Form
            action={`${base}/entitlements/${id}/revoke`}
            method="post"
            options={{ preserveScroll: true }}
            className="mt-2 flex gap-2"
        >
            {({ processing }) => (
                <>
                    <Input
                        name="reason"
                        placeholder="Reason"
                        required
                        aria-label="Reason to remove the override"
                        className="h-8"
                    />
                    <Button
                        type="submit"
                        size="sm"
                        variant="ghost"
                        disabled={processing}
                    >
                        Back to the plan
                    </Button>
                </>
            )}
        </Form>
    );
}
