import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { show } from '@/routes/mel/collect/forms';

type Question = {
    name: string;
    label: string;
    type: string;
    answered: number;
    choices?: { label: string; count: number }[];
    stats?: { min: number; median: number; max: number } | null;
};

type Props = {
    form: { id: string; name: string; version: number | null };
    accepted: number;
    questions: Question[];
    personalHidden: boolean;
};

const number = (value: number) =>
    value.toLocaleString(undefined, { maximumFractionDigits: 2 });

export default function FormSummary({
    form,
    accepted,
    questions,
    personalHidden,
}: Props) {
    return (
        <>
            <Head title={`${form.name}: summary`} />
            <PageHeader
                title={form.name}
                description={`Summary of ${accepted} accepted submission${accepted === 1 ? '' : 's'}. Submissions not yet accepted, and rejected ones, are left out. This is analysis of collected data, not a verified indicator result.`}
                actions={
                    <Button variant="outline" asChild>
                        <Link href={show(form.id)}>The form</Link>
                    </Button>
                }
            />
            {personalHidden && (
                <p className="mb-3 text-sm text-muted-foreground">
                    Personal questions are left out: you do not have access to
                    personal data.
                </p>
            )}
            <div className="grid gap-3 md:grid-cols-2">
                {questions.map((question) => (
                    <Panel key={question.name}>
                        <PanelHeader
                            title={question.label}
                            description={`${question.answered} answered`}
                        />
                        <PanelBody className="text-sm">
                            {question.choices && (
                                <ul className="grid gap-1.5">
                                    {question.choices.map((choice) => {
                                        const share =
                                            question.answered === 0
                                                ? 0
                                                : (choice.count /
                                                      question.answered) *
                                                  100;

                                        return (
                                            <li
                                                key={choice.label}
                                                className="grid gap-0.5"
                                            >
                                                <div className="flex justify-between">
                                                    <span>{choice.label}</span>
                                                    <span className="text-muted-foreground tabular-nums">
                                                        {choice.count} ·{' '}
                                                        {Math.round(share)}%
                                                    </span>
                                                </div>
                                                <div
                                                    className="h-1.5 rounded bg-muted"
                                                    aria-hidden
                                                >
                                                    <div
                                                        className="h-1.5 rounded bg-primary"
                                                        style={{
                                                            width: `${share}%`,
                                                        }}
                                                    />
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                            {question.stats !== undefined &&
                                (question.stats === null ? (
                                    <p className="text-muted-foreground">
                                        No answers yet.
                                    </p>
                                ) : (
                                    <dl className="grid grid-cols-3 gap-2 text-center">
                                        {(
                                            ['min', 'median', 'max'] as const
                                        ).map((key) => (
                                            <div
                                                key={key}
                                                className="rounded-md border p-2"
                                            >
                                                <dt className="text-xs text-muted-foreground capitalize">
                                                    {key}
                                                </dt>
                                                <dd className="font-semibold tabular-nums">
                                                    {number(
                                                        question.stats![key],
                                                    )}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                ))}
                            {!question.choices &&
                                question.stats === undefined && (
                                    <p className="text-muted-foreground">
                                        {question.answered} answers.
                                    </p>
                                )}
                        </PanelBody>
                    </Panel>
                ))}
            </div>
        </>
    );
}
