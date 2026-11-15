import { Form, Head } from '@inertiajs/react';
import { Button } from '@miles/ui/components/ui/button';
import { FolderKanban, HandCoins } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { store } from '@/routes/account-type';

type AccountType = 'funder' | 'implementer';

const TYPES: {
    value: AccountType;
    title: string;
    description: string;
    points: string[];
    icon: LucideIcon;
}[] = [
    {
        value: 'funder',
        title: 'Funder or donor',
        description: 'We fund other organizations and follow their work.',
        points: [
            'Invite the organizations you fund',
            'Grants, payments and their reports',
            'Find projects seeking funding',
        ],
        icon: HandCoins,
    },
    {
        value: 'implementer',
        title: 'Implementer',
        description: 'We carry out projects, often with funding from donors.',
        points: [
            'Projects, programmes, tasks and people',
            'Grants received, funders and procurement',
            'Approvals, advances and petty cash',
        ],
        icon: FolderKanban,
    },
];

/**
 * The first visit to Projects: what the organization uses it as. Its menu
 * follows. Only someone who manages the organization chooses.
 */
export default function ChooseAccountType({
    organization,
    canChoose,
    suggested,
}: {
    organization: string;
    canChoose: boolean;
    suggested: AccountType | null;
}) {
    const [choice, setChoice] = useState<AccountType | null>(suggested);

    if (!canChoose) {
        return (
            <>
                <Head title="Almost there" />
                <h1 className="mb-2 text-xl font-semibold">
                    {organization} is not set up yet
                </h1>
                <p className="text-sm text-muted-foreground">
                    Someone who manages {organization} needs to say whether it
                    is a funder or an implementer before Projects opens. Ask
                    them, then come back to this page.
                </p>
            </>
        );
    }

    return (
        <>
            <Head title="Choose your account type" />
            <h1 className="mb-1 text-xl font-semibold">
                How will {organization} use Projects?
            </h1>
            <p className="mb-6 text-sm text-muted-foreground">
                Your menu is based on this choice. You can change it later in
                Administration → Settings.
            </p>

            <Form {...store.form()} className="grid gap-4">
                {({ errors, processing }) => (
                    <>
                        <fieldset className="grid gap-3">
                            <legend className="sr-only">Account type</legend>
                            {TYPES.map((type) => {
                                const selected = choice === type.value;
                                const Icon = type.icon;

                                return (
                                    <label
                                        key={type.value}
                                        className={`flex cursor-pointer gap-3 rounded-lg border bg-card p-4 transition-colors hover:border-primary/60 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring ${
                                            selected
                                                ? 'border-primary ring-1 ring-primary'
                                                : ''
                                        }`}
                                    >
                                        <input
                                            type="radio"
                                            name="account_type"
                                            value={type.value}
                                            checked={selected}
                                            onChange={() =>
                                                setChoice(type.value)
                                            }
                                            className="sr-only"
                                        />
                                        <span
                                            className={`flex size-10 shrink-0 items-center justify-center rounded-md ${
                                                selected
                                                    ? 'bg-primary text-primary-foreground'
                                                    : 'bg-muted text-muted-foreground'
                                            }`}
                                            aria-hidden
                                        >
                                            <Icon className="size-5" />
                                        </span>
                                        <span className="grid gap-1">
                                            <span className="font-medium">
                                                {type.title}
                                            </span>
                                            <span className="text-sm text-muted-foreground">
                                                {type.description}
                                            </span>
                                            <ul className="mt-1 list-disc pl-4 text-sm text-muted-foreground">
                                                {type.points.map((point) => (
                                                    <li key={point}>{point}</li>
                                                ))}
                                            </ul>
                                        </span>
                                    </label>
                                );
                            })}
                        </fieldset>
                        {errors.account_type && (
                            <p className="text-sm text-destructive">
                                {errors.account_type}
                            </p>
                        )}
                        {errors.funding && (
                            <p className="text-sm text-destructive">
                                {errors.funding}
                            </p>
                        )}
                        <Button
                            type="submit"
                            disabled={processing || choice === null}
                        >
                            Continue
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}
