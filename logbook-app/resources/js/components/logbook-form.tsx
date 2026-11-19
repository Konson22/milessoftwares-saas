import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@miles/ui/components/ui/button';
import { Check, TextArea, TextField } from '@/components/fields';
import { LOGBOOK_ICONS } from '@/components/logbook-icon';

export type LogbookData = {
    name: string;
    description: string | null;
    icon: string | null;
    restricted: boolean;
};

/**
 * Name, description, icon and whether only listed members reach it.
 */
export function LogbookForm({
    initial,
    url,
    method,
    submitLabel,
    onDone,
}: {
    initial: LogbookData;
    url: string;
    method: 'post' | 'put';
    submitLabel: string;
    onDone?: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [data, setData] = useState({
        name: initial.name,
        description: initial.description ?? '',
        icon: initial.icon ?? 'book',
        restricted: initial.restricted,
    });
    const [busy, setBusy] = useState(false);

    return (
        <form
            className="grid gap-4"
            onSubmit={(e) => {
                e.preventDefault();
                setBusy(true);
                router.visit(url, {
                    method,
                    data,
                    preserveScroll: true,
                    onSuccess: () => onDone?.(),
                    onFinish: () => setBusy(false),
                });
            }}
        >
            <TextField
                label="Name"
                value={data.name}
                onChange={(name) => setData({ ...data, name })}
                error={errors.name}
                placeholder="e.g. Facility Management"
                required
            />
            <TextArea
                label="Description"
                value={data.description}
                onChange={(description) => setData({ ...data, description })}
                error={errors.description}
                rows={2}
            />
            <fieldset className="grid gap-1.5">
                <legend className="mb-1.5 text-sm font-medium">Icon</legend>
                <div className="flex flex-wrap gap-2">
                    {Object.entries(LOGBOOK_ICONS).map(([key, Icon]) => (
                        <button
                            key={key}
                            type="button"
                            aria-label={key}
                            aria-pressed={data.icon === key}
                            onClick={() => setData({ ...data, icon: key })}
                            className={`rounded-md border p-2 ${
                                data.icon === key
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'hover:bg-muted'
                            }`}
                        >
                            <Icon className="size-4" />
                        </button>
                    ))}
                </div>
            </fieldset>
            <div className="grid gap-1">
                <Check
                    label="Restricted: only members listed on this logbook reach it"
                    checked={data.restricted}
                    onChange={(restricted) => setData({ ...data, restricted })}
                />
                <p className="text-xs text-muted-foreground">
                    Open logbooks are reached by everyone with a Logbook role in
                    Tatook. Managers always reach every logbook.
                </p>
            </div>
            <div>
                <Button type="submit" disabled={busy}>
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}
