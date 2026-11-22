import { Head, Link, router, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    ArrowLeft,
    Calendar,
    CalendarClock,
    Check,
    CheckSquare,
    CircleDot,
    Clock,
    Copy,
    FileText,
    Fuel,
    Hash,
    ListChecks,
    Monitor,
    Paperclip,
    ShieldCheck,
    TextCursorInput,
    ToggleLeft,
    Truck,
    User,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@miles/ui/components/ui/button';
import { TextField } from '@/components/fields';
import { show as logbookShow } from '@/routes/logbooks';
import { fromStarter } from '@/routes/sheets';

type Template = {
    key: string;
    name: string;
    code: string;
    description: string;
    reviewRequired: boolean;
    fields: {
        label: string;
        kind: string;
        type: string;
        required: boolean;
        choices: string[];
    }[];
};

type Props = {
    logbook: { id: string; name: string };
    templates: Template[];
};

/** A picture per starter; anything new falls back to a sheet. */
const TEMPLATE_ICONS: Record<string, LucideIcon> = {
    daily_activity: ListChecks,
    generator_operation: Fuel,
    vehicle_movement: Truck,
    it_maintenance: Monitor,
};

/** A small mark per field type (App\Logbook\Fields::TYPES). */
const KIND_ICONS: Record<string, LucideIcon> = {
    text: TextCursorInput,
    textarea: FileText,
    integer: Hash,
    decimal: Hash,
    date: Calendar,
    time: Clock,
    datetime: CalendarClock,
    select_one: CircleDot,
    select_multiple: CheckSquare,
    yes_no: ToggleLeft,
    file: Paperclip,
    user: User,
};

/**
 * Ready-made log sheets: copied into the logbook as a draft, changed as
 * needed, then published. Pick one on the left, look it over on the right;
 * nothing is created until "Copy into logbook".
 */
export default function Starters({ logbook, templates }: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const first = templates[0];
    const [chosen, setChosen] = useState<string | null>(first?.key ?? null);
    const [data, setData] = useState({
        name: first?.name ?? '',
        code: first?.code ?? '',
    });
    const [busy, setBusy] = useState(false);
    const preview = useRef<HTMLDivElement>(null);

    const template = templates.find((t) => t.key === chosen) ?? null;

    function choose(t: Template) {
        setChosen(t.key);
        setData({ name: t.name, code: t.code });
        // On narrow screens the preview sits below the list.
        if (window.matchMedia('(max-width: 63.99rem)').matches) {
            preview.current?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        }
    }

    return (
        <>
            <Head title="Starter templates" />
            <Link
                href={logbookShow(logbook.id)}
                className="mb-2 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-primary"
            >
                <ArrowLeft className="size-4" aria-hidden />
                {logbook.name}
            </Link>
            <PageHeader title="Start from a template" />
            <p className="-mt-1 mb-5 max-w-2xl text-sm text-muted-foreground">
                Each starter is copied into{' '}
                <span className="font-medium text-foreground">
                    {logbook.name}
                </span>{' '}
                as a draft. Add, remove or rename fields, then publish when it
                fits the way your team works.
            </p>

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)] lg:items-start">
                <div
                    role="radiogroup"
                    aria-label="Starter templates"
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2"
                >
                    {templates.map((t) => (
                        <TemplateCard
                            key={t.key}
                            template={t}
                            selected={t.key === chosen}
                            onSelect={() => choose(t)}
                        />
                    ))}
                </div>

                <div ref={preview} className="scroll-mt-4 lg:sticky lg:top-4">
                    {template && (
                        <section
                            aria-label={`${template.name} preview`}
                            className="overflow-hidden rounded-lg border bg-card shadow-[0_1px_2px_#e3e3e3]"
                        >
                            <header className="flex items-start gap-3 border-b border-[#ebebeb] p-5">
                                <TemplateIcon
                                    templateKey={template.key}
                                    className="size-12"
                                />
                                <div className="grid min-w-0 gap-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="text-base font-semibold">
                                            {template.name}
                                        </h2>
                                        <CodeChip code={template.code} />
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {template.description}
                                    </p>
                                    <Facts template={template} />
                                </div>
                            </header>

                            <div className="p-5">
                                <h3 className="mb-2 text-sm font-semibold">
                                    Fields
                                </h3>
                                <ol className="divide-y divide-[#ebebeb] rounded-md border">
                                    {template.fields.map((f, i) => (
                                        <FieldRow
                                            key={f.label}
                                            field={f}
                                            number={i + 1}
                                        />
                                    ))}
                                </ol>
                            </div>

                            <form
                                className="grid gap-4 border-t border-[#ebebeb] bg-[#fafafa] p-5"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    setBusy(true);
                                    router.post(
                                        fromStarter(logbook.id).url,
                                        { template: template.key, ...data },
                                        { onFinish: () => setBusy(false) },
                                    );
                                }}
                            >
                                <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_9rem]">
                                    <TextField
                                        label="Log sheet name"
                                        value={data.name}
                                        onChange={(name) =>
                                            setData({ ...data, name })
                                        }
                                        error={errors.name}
                                        required
                                    />
                                    <TextField
                                        label="Code"
                                        value={data.code}
                                        onChange={(code) =>
                                            setData({
                                                ...data,
                                                code: code.toUpperCase(),
                                            })
                                        }
                                        error={errors.code}
                                        required
                                    />
                                </div>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <p className="text-xs text-muted-foreground">
                                        Saved as a draft. Nobody can write
                                        entries until you publish it.
                                    </p>
                                    <Button type="submit" disabled={busy}>
                                        <Copy /> Copy into logbook
                                    </Button>
                                </div>
                            </form>
                        </section>
                    )}
                </div>
            </div>
        </>
    );
}

function TemplateCard({
    template: t,
    selected,
    onSelect,
}: {
    template: Template;
    selected: boolean;
    onSelect: () => void;
}) {
    const required = t.fields.filter((f) => f.required).length;

    return (
        <button
            type="button"
            role="radio"
            aria-checked={selected}
            onClick={onSelect}
            className={`group relative flex flex-col rounded-lg border bg-card text-left shadow-[0_1px_2px_#e3e3e3] transition-[border-color,box-shadow] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
                selected
                    ? 'border-primary shadow-[0_0_0_1px_#0353ff]'
                    : 'hover:border-[#c9d8ff] hover:shadow-[0_2px_6px_#d6d6d6]'
            }`}
        >
            <span className="flex items-start gap-3 p-4">
                <TemplateIcon templateKey={t.key} className="size-10" />
                <span className="grid min-w-0 flex-1 gap-1">
                    <span className="flex items-center gap-2 pr-6">
                        <span
                            className={`truncate font-semibold ${selected ? 'text-primary' : 'group-hover:text-primary'}`}
                        >
                            {t.name}
                        </span>
                    </span>
                    <span className="line-clamp-2 text-sm text-muted-foreground">
                        {t.description}
                    </span>
                </span>
            </span>
            <span
                aria-hidden
                className={`absolute top-3 right-3 grid size-5 place-items-center rounded-full border ${
                    selected
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-[#d1d1d1] bg-card text-transparent'
                }`}
            >
                <Check className="size-3" strokeWidth={3} />
            </span>
            <span className="mt-auto flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-[#ebebeb] bg-[#fafafa] px-4 py-2.5 text-xs text-muted-foreground">
                <span>
                    <span className="font-semibold text-foreground tabular-nums">
                        {t.fields.length}
                    </span>{' '}
                    fields
                </span>
                <span>
                    <span className="font-semibold text-foreground tabular-nums">
                        {required}
                    </span>{' '}
                    required
                </span>
                <span className="ml-auto inline-flex items-center gap-1">
                    {t.reviewRequired ? (
                        <>
                            <ShieldCheck
                                className="size-3.5 text-[#0b7a3e]"
                                aria-hidden
                            />
                            Reviewed
                        </>
                    ) : (
                        'No review'
                    )}
                </span>
            </span>
        </button>
    );
}

function TemplateIcon({
    templateKey,
    className,
}: {
    templateKey: string;
    className: string;
}) {
    const Icon = TEMPLATE_ICONS[templateKey] ?? FileText;

    return (
        <span
            className={`grid shrink-0 place-items-center rounded-md bg-[#e8efff] text-primary ${className}`}
        >
            <Icon className="size-1/2" aria-hidden />
        </span>
    );
}

function CodeChip({ code }: { code: string }) {
    return (
        <span className="rounded-sm border border-[#d1d1d1] bg-[#f5f5f5] px-1.5 font-mono text-xs text-muted-foreground">
            {code}
        </span>
    );
}

function Facts({ template }: { template: Template }) {
    const required = template.fields.filter((f) => f.required).length;

    return (
        <ul className="mt-1 flex flex-wrap gap-1.5 text-xs">
            <li className="rounded-sm bg-[#f0f0f0] px-2 py-0.5">
                {template.fields.length} fields · {required} required
            </li>
            {template.reviewRequired ? (
                <li className="inline-flex items-center gap-1 rounded-sm bg-[#dff6e8] px-2 py-0.5 text-[#0b5a2f]">
                    <ShieldCheck className="size-3.5" aria-hidden />
                    Supervisor reviews each entry
                </li>
            ) : (
                <li className="rounded-sm bg-[#f0f0f0] px-2 py-0.5">
                    Entries are final when saved
                </li>
            )}
        </ul>
    );
}

function FieldRow({
    field: f,
    number,
}: {
    field: Template['fields'][number];
    number: number;
}) {
    const Icon = KIND_ICONS[f.kind] ?? TextCursorInput;

    return (
        <li className="flex items-start gap-3 px-3 py-2.5">
            <span className="mt-0.5 w-5 shrink-0 text-right text-xs text-muted-foreground tabular-nums">
                {number}
            </span>
            <span className="grid min-w-0 flex-1 gap-1">
                <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    <span className="text-sm font-medium">{f.label}</span>
                    {f.required ? (
                        <span className="rounded-sm bg-[#fde7e9] px-1.5 text-[0.6875rem] font-medium text-[#a4262c]">
                            Required
                        </span>
                    ) : (
                        <span className="text-[0.6875rem] text-muted-foreground">
                            Optional
                        </span>
                    )}
                </span>
                {f.choices.length > 0 && (
                    <span className="flex flex-wrap gap-1">
                        {f.choices.map((c) => (
                            <span
                                key={c}
                                className="rounded-sm border border-[#e0e0e0] bg-card px-1.5 text-xs text-muted-foreground"
                            >
                                {c}
                            </span>
                        ))}
                    </span>
                )}
            </span>
            <span className="inline-flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground">
                <Icon className="size-3.5" aria-hidden />
                {f.type}
            </span>
        </li>
    );
}
