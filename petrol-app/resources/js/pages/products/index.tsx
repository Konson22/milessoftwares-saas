import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    Fuel,
    Pencil,
    Plus,
    Sparkles,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { cn } from '@miles/ui/lib/utils';
import { Field } from '@/components/field';
import { formatMoney } from '@/lib/money';
import { store, update } from '@/routes/products';
import { index as pumpsIndex } from '@/routes/pumps';
import { index as settingsIndex } from '@/routes/settings';
import { index as tanksIndex } from '@/routes/tanks';

type Product = {
    id: string;
    name: string;
    code: string;
    active: boolean;
    /** This station's selling price per litre now, if one is set. */
    priceMinor: number | null;
    priceCurrency: string | null;
    /** Active tanks and nozzles at this station. */
    tanks: number;
    nozzles: number;
};

type Props = {
    products: Product[];
    canManage: boolean;
    canPrice: boolean;
};

/** Grades most stations sell, offered while the business has not added them. */
const SUGGESTIONS = [
    { name: 'Petrol', code: 'PMS', hint: 'Premium Motor Spirit' },
    { name: 'Diesel', code: 'AGO', hint: 'Automotive Gas Oil' },
    { name: 'Kerosene', code: 'DPK', hint: 'Dual Purpose Kerosene' },
    { name: 'Premium petrol', code: 'SUPER', hint: 'Higher octane' },
];

export default function Products({ products, canManage, canPrice }: Props) {
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<string | null>(null);

    const active = products.filter((product) => product.active);
    const unpriced = active.filter((product) => product.priceMinor === null);
    const taken = new Set(
        products.map((product) => product.code.toUpperCase()),
    );
    const suggestions = SUGGESTIONS.filter(
        (suggestion) => !taken.has(suggestion.code),
    );
    const showAdd = canManage && (adding || products.length === 0);
    const pricesUrl = settingsIndex({ query: { section: 'prices' } }).url;

    return (
        <>
            <Head title="Fuel grades" />
            <PageHeader
                title="Fuel grades"
                badges={
                    products.length > 0 && (
                        <StatusBadge>{active.length} active</StatusBadge>
                    )
                }
                actions={
                    canManage &&
                    !showAdd && (
                        <Button onClick={() => setAdding(true)}>
                            <Plus aria-hidden />
                            Add grade
                        </Button>
                    )
                }
            />

            <div className="grid gap-3">
                <p className="text-sm text-muted-foreground">
                    A grade is a type of fuel you sell, such as Petrol or
                    Diesel. Grades are shared by every station; each station
                    sets its own price. Tanks, nozzles, prices and deliveries
                    all name a grade, so add them first.
                </p>

                {unpriced.length > 0 && (
                    <div
                        role="status"
                        className="flex flex-wrap items-center gap-x-3 gap-y-1 border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200"
                    >
                        <AlertTriangle
                            className="size-4 shrink-0"
                            aria-hidden
                        />
                        <span className="min-w-0 flex-1">
                            {unpriced.length === 1
                                ? `${unpriced[0].name} has no selling price at this station yet, so it cannot be sold here.`
                                : `${unpriced.length} grades have no selling price at this station yet, so they cannot be sold here.`}
                        </span>
                        {canPrice && (
                            <Link
                                href={pricesUrl}
                                className="font-medium underline underline-offset-4"
                            >
                                Set prices
                            </Link>
                        )}
                    </div>
                )}

                {showAdd && (
                    <AddGrade
                        suggestions={suggestions}
                        onDone={() => setAdding(false)}
                        canCancel={products.length > 0}
                    />
                )}

                {products.length === 0 ? (
                    !canManage && (
                        <Panel>
                            <EmptyState icon={Fuel} title="No fuel grades yet">
                                Someone who manages grades adds the fuels this
                                business sells.
                            </EmptyState>
                        </Panel>
                    )
                ) : (
                    <Panel>
                        <PanelHeader
                            title="Grades"
                            description="Price, tanks and nozzles are for this station."
                        />
                        <ul className="divide-y">
                            {products.map((product) =>
                                editing === product.id ? (
                                    <EditGrade
                                        key={product.id}
                                        product={product}
                                        onDone={() => setEditing(null)}
                                    />
                                ) : (
                                    <GradeRow
                                        key={product.id}
                                        product={product}
                                        canManage={canManage}
                                        canPrice={canPrice}
                                        pricesUrl={pricesUrl}
                                        onEdit={() => setEditing(product.id)}
                                    />
                                ),
                            )}
                        </ul>
                    </Panel>
                )}
            </div>
        </>
    );
}

function CodeMark({ code, active }: { code: string; active: boolean }) {
    return (
        <span
            className={cn(
                'grid h-10 min-w-12 shrink-0 place-items-center px-2 font-mono text-xs font-semibold tracking-wide',
                active
                    ? 'bg-primary/10 text-primary'
                    : 'bg-muted text-muted-foreground',
            )}
            aria-hidden
        >
            {code}
        </span>
    );
}

function GradeRow({
    product,
    canManage,
    canPrice,
    pricesUrl,
    onEdit,
}: {
    product: Product;
    canManage: boolean;
    canPrice: boolean;
    pricesUrl: string;
    onEdit: () => void;
}) {
    const { currency } = usePage().props;

    return (
        <li
            className={cn(
                'grid gap-3 px-3 py-3 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-center',
                !product.active && 'opacity-70',
            )}
        >
            <div className="flex min-w-0 items-center gap-3">
                <CodeMark code={product.code} active={product.active} />
                <div className="min-w-0">
                    <p className="flex flex-wrap items-center gap-2 font-medium">
                        <span className="truncate">{product.name}</span>
                        {!product.active && <StatusBadge>Inactive</StatusBadge>}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        Code {product.code}
                    </p>
                </div>
            </div>

            <dl className="grid grid-cols-3 gap-4 text-sm sm:flex sm:gap-6">
                <div>
                    <dt className="text-xs text-muted-foreground">
                        Price here
                    </dt>
                    <dd className="font-medium tabular-nums">
                        {product.priceMinor !== null ? (
                            <>
                                {formatMoney(
                                    product.priceMinor,
                                    product.priceCurrency ?? currency ?? 'USD',
                                )}
                                <span className="text-xs font-normal text-muted-foreground">
                                    {' '}
                                    / L
                                </span>
                            </>
                        ) : product.active && canPrice ? (
                            <Link
                                href={pricesUrl}
                                className="text-amber-700 underline underline-offset-4 dark:text-amber-300"
                            >
                                Set price
                            </Link>
                        ) : (
                            <span className="text-muted-foreground">—</span>
                        )}
                    </dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">Tanks</dt>
                    <dd className="font-medium tabular-nums">
                        <Link
                            href={tanksIndex().url}
                            className="hover:underline"
                        >
                            {product.tanks}
                        </Link>
                    </dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">Nozzles</dt>
                    <dd className="font-medium tabular-nums">
                        <Link
                            href={pumpsIndex().url}
                            className="hover:underline"
                        >
                            {product.nozzles}
                        </Link>
                    </dd>
                </div>
            </dl>

            {canManage && (
                <div className="sm:justify-self-end">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={onEdit}
                        aria-label={`Edit ${product.name}`}
                    >
                        <Pencil aria-hidden />
                        Edit
                    </Button>
                </div>
            )}
        </li>
    );
}

function AddGrade({
    suggestions,
    canCancel,
    onDone,
}: {
    suggestions: typeof SUGGESTIONS;
    canCancel: boolean;
    onDone: () => void;
}) {
    const [name, setName] = useState('');
    const [code, setCode] = useState('');

    return (
        <Panel>
            <PanelHeader
                title="Add a grade"
                description="The name staff and receipts show, and a short code unique in this business."
                actions={
                    canCancel && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={onDone}
                            aria-label="Close"
                        >
                            <X aria-hidden />
                        </Button>
                    )
                }
            />
            <PanelBody className="grid gap-4">
                {suggestions.length > 0 && (
                    <div className="grid gap-2">
                        <p className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                            <Sparkles className="size-3.5" aria-hidden />
                            Common grades
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {suggestions.map((suggestion) => {
                                const chosen =
                                    name === suggestion.name &&
                                    code === suggestion.code;

                                return (
                                    <button
                                        key={suggestion.code}
                                        type="button"
                                        aria-pressed={chosen}
                                        onClick={() => {
                                            setName(suggestion.name);
                                            setCode(suggestion.code);
                                        }}
                                        className={cn(
                                            'flex items-center gap-2 border px-3 py-1.5 text-left text-sm transition-colors hover:bg-accent',
                                            chosen &&
                                                'border-primary bg-primary/5',
                                        )}
                                    >
                                        <span className="font-mono text-xs font-semibold text-primary">
                                            {suggestion.code}
                                        </span>
                                        <span>
                                            {suggestion.name}
                                            <span className="block text-xs text-muted-foreground">
                                                {suggestion.hint}
                                            </span>
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                )}

                <Form
                    {...store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        setName('');
                        setCode('');
                    }}
                    className="grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto] sm:items-start"
                >
                    {({ errors, processing }) => (
                        <>
                            <Field id="name" label="Name" error={errors.name}>
                                <Input
                                    id="name"
                                    name="name"
                                    value={name}
                                    onChange={(event) =>
                                        setName(event.target.value)
                                    }
                                    placeholder="e.g. Diesel"
                                    maxLength={255}
                                    required
                                />
                            </Field>
                            <Field id="code" label="Code" error={errors.code}>
                                <Input
                                    id="code"
                                    name="code"
                                    value={code}
                                    onChange={(event) =>
                                        setCode(
                                            event.target.value.toUpperCase(),
                                        )
                                    }
                                    placeholder="e.g. AGO"
                                    className="font-mono uppercase"
                                    maxLength={32}
                                    autoCapitalize="characters"
                                    required
                                />
                            </Field>
                            <input type="hidden" name="active" value="1" />
                            <Button
                                type="submit"
                                disabled={processing}
                                className="sm:mt-[1.375rem]"
                            >
                                <Plus aria-hidden />
                                Add grade
                            </Button>
                        </>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}

function EditGrade({
    product,
    onDone,
}: {
    product: Product;
    onDone: () => void;
}) {
    const [active, setActive] = useState(product.active);

    return (
        <li className="bg-muted/40 px-3 py-3">
            <Form
                {...update.form(product.id)}
                options={{ preserveScroll: true }}
                onSuccess={onDone}
                className="grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto_auto] sm:items-start"
            >
                {({ errors, processing }) => (
                    <>
                        <Field
                            id={`name-${product.id}`}
                            label="Name"
                            error={errors.name}
                        >
                            <Input
                                id={`name-${product.id}`}
                                name="name"
                                defaultValue={product.name}
                                maxLength={255}
                                autoFocus
                                required
                            />
                        </Field>
                        <Field
                            id={`code-${product.id}`}
                            label="Code"
                            error={errors.code}
                        >
                            <Input
                                id={`code-${product.id}`}
                                name="code"
                                defaultValue={product.code}
                                onChange={(event) => {
                                    event.target.value =
                                        event.target.value.toUpperCase();
                                }}
                                className="font-mono uppercase"
                                maxLength={32}
                                required
                            />
                        </Field>
                        <div className="grid content-start gap-1.5">
                            <span className="text-sm font-medium">Status</span>
                            <input
                                type="hidden"
                                name="active"
                                value={active ? '1' : '0'}
                            />
                            <button
                                type="button"
                                role="switch"
                                aria-checked={active}
                                onClick={() => setActive(!active)}
                                className="flex h-8 items-center gap-2 text-sm"
                            >
                                <span
                                    className={cn(
                                        'relative h-5 w-9 rounded-full transition-colors',
                                        active ? 'bg-primary' : 'bg-input',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'absolute top-0.5 size-4 rounded-full bg-white shadow transition-[left]',
                                            active
                                                ? 'left-[1.125rem]'
                                                : 'left-0.5',
                                        )}
                                    />
                                </span>
                                {active ? 'Active' : 'Inactive'}
                            </button>
                        </div>
                        <div className="flex gap-2 sm:mt-[1.375rem]">
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                            >
                                <Check aria-hidden />
                                Save
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={onDone}
                            >
                                Cancel
                            </Button>
                        </div>
                        {!active && product.active && (
                            <p className="text-xs text-muted-foreground sm:col-span-4">
                                An inactive grade is no longer offered for new
                                tanks, nozzles, prices or deliveries. Its
                                history stays.
                            </p>
                        )}
                    </>
                )}
            </Form>
        </li>
    );
}
