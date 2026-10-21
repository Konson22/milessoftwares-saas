import { UserRound, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { lookup } from '@/routes/customers';

export type RegisteredCustomer = {
    id: string;
    number: string;
    name: string;
    phone: string | null;
};

type Mode = 'walk-in' | 'named' | 'registered';

/**
 * Who the sale is for, all optional unless the branch says otherwise: a
 * walk-in customer (nothing asked), a name and phone for this sale only, or
 * a registered customer, whose purchases are kept together. Sends
 * customer_name, customer_phone and customer_id.
 */
export function CustomerFields({
    required,
    canFindRegistered,
    errors,
}: {
    /** Why the branch needs the customer now, or null when it does not. */
    required: string | null;
    canFindRegistered: boolean;
    errors: Partial<Record<string, string>>;
}) {
    const [mode, setMode] = useState<Mode>(required ? 'named' : 'walk-in');
    const [search, setSearch] = useState('');
    const [found, setFound] = useState<RegisteredCustomer[]>([]);
    const [chosen, setChosen] = useState<RegisteredCustomer | null>(null);

    // A walk-in sale is not possible once the branch needs the customer.
    useEffect(() => {
        if (required && mode === 'walk-in') {
            setMode('named');
        }
    }, [required, mode]);

    useEffect(() => {
        const term = search.trim();
        if (mode !== 'registered' || term.length < 2) {
            setFound([]);

            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(lookup.url({ query: { search: term } }), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : null))
                .then(
                    (body: { customers: RegisteredCustomer[] } | null) =>
                        body && setFound(body.customers),
                )
                .catch(() => undefined);
        }, 200);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [mode, search]);

    const modes: { value: Mode; label: string }[] = [
        ...(required ? [] : [{ value: 'walk-in' as const, label: 'Walk-in' }]),
        { value: 'named', label: 'Name' },
        ...(canFindRegistered
            ? [{ value: 'registered' as const, label: 'Registered' }]
            : []),
    ];

    return (
        <fieldset className="grid gap-2">
            <legend className="mb-1.5 text-sm font-medium">
                Customer{required ? '' : ' (optional)'}
            </legend>
            {required && (
                <p className="text-sm text-muted-foreground">{required}</p>
            )}
            <div
                role="radiogroup"
                aria-label="Customer"
                className="flex rounded-md border p-0.5"
            >
                {modes.map((option) => (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={mode === option.value}
                        onClick={() => setMode(option.value)}
                        className="flex-1 rounded px-2 py-1 text-sm aria-checked:bg-muted aria-checked:font-medium"
                    >
                        {option.label}
                    </button>
                ))}
            </div>

            {mode === 'walk-in' && (
                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                    <UserRound className="size-4" aria-hidden />
                    Walk-in Customer
                </p>
            )}

            {mode === 'named' && (
                <div className="grid gap-2">
                    <div className="grid gap-1.5">
                        <Label htmlFor="customer_name">Name</Label>
                        <Input
                            id="customer_name"
                            name="customer_name"
                            autoComplete="off"
                            aria-invalid={
                                errors.customer_name ? true : undefined
                            }
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="customer_phone">Phone (optional)</Label>
                        <Input
                            id="customer_phone"
                            name="customer_phone"
                            type="tel"
                            autoComplete="off"
                        />
                    </div>
                </div>
            )}

            {mode === 'registered' &&
                (chosen ? (
                    <div className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                        <input
                            type="hidden"
                            name="customer_id"
                            value={chosen.id}
                        />
                        <span className="grid">
                            <span className="font-medium">{chosen.name}</span>
                            <span className="text-muted-foreground">
                                {chosen.number}
                                {chosen.phone && ` · ${chosen.phone}`}
                            </span>
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="ml-auto size-8"
                            aria-label={`Not ${chosen.name}`}
                            onClick={() => setChosen(null)}
                        >
                            <X className="size-4" aria-hidden />
                        </Button>
                    </div>
                ) : (
                    <div className="grid gap-1.5">
                        <Label htmlFor="customer_search">
                            Find a registered customer
                        </Label>
                        <Input
                            id="customer_search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Name, number or phone"
                            autoComplete="off"
                        />
                        {found.length > 0 && (
                            <ul className="divide-y rounded-md border">
                                {found.map((customer) => (
                                    <li key={customer.id}>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setChosen(customer);
                                                setSearch('');
                                            }}
                                            className="flex w-full flex-wrap gap-x-2 px-3 py-1.5 text-left text-sm hover:bg-muted/50"
                                        >
                                            <span className="font-medium">
                                                {customer.name}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {customer.number}
                                                {customer.phone &&
                                                    ` · ${customer.phone}`}
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {search.trim().length >= 2 && found.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No registered customer matches.
                            </p>
                        )}
                    </div>
                ))}

            {(errors.customer_name ?? errors.customer_id) && (
                <p className="text-sm text-destructive">
                    {errors.customer_name ?? errors.customer_id}
                </p>
            )}
        </fieldset>
    );
}
