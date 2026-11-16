import { Head, Link } from '@inertiajs/react';
import { BarChart3 } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Field, selectClass } from '@/mel/components/fields';
import type { Option } from '@/mel/lib/mel';
import { show } from '@/routes/mel/reports';

type Props = {
    reports: { key: string; title: string }[];
    programmes: Option[];
    sees: 'all' | 'verified' | 'published' | null;
};

const ABOUT: Record<string, string> = {
    iptt: 'Every indicator by period: baseline, target, actual and how it stands.',
    framework:
        'The results framework with each indicator’s latest figure, means of verification and assumptions.',
    'target-actual':
        'One period: target against actual for the programme and for each unit.',
    completeness:
        'What has been reported for each indicator, unit and period, and what is missing or late.',
    verification:
        'Where results stand in review: submitted, sent back, verified, published.',
    programme:
        'How many indicators are on track, need attention or are off track, by period, with linked projects.',
};

export default function Reports({ reports, programmes, sees }: Props) {
    const [programme, setProgramme] = useState(programmes[0]?.value ?? '');

    return (
        <>
            <Head title="Reports" />
            <PageHeader title="Reports" />

            {programmes.length === 0 ? (
                <Panel>
                    <EmptyState icon={BarChart3} title="No programmes">
                        Reports cover the programmes you have access to.
                    </EmptyState>
                </Panel>
            ) : (
                <div className="grid gap-3">
                    <Panel>
                        <PanelBody className="flex flex-wrap items-end gap-3">
                            <Field id="programme" label="Programme">
                                <select
                                    id="programme"
                                    value={programme}
                                    onChange={(event) =>
                                        setProgramme(event.target.value)
                                    }
                                    className={`${selectClass} w-auto`}
                                >
                                    {programmes.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <p className="text-sm text-muted-foreground">
                                {sees === 'published'
                                    ? 'Reports show published results only.'
                                    : 'Reports show verified results only, never drafts or results waiting for review.'}
                            </p>
                        </PanelBody>
                    </Panel>

                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {reports.map((report) => (
                            <Link
                                key={report.key}
                                href={show(report.key, {
                                    query: { programme },
                                })}
                                className="block border bg-card transition-colors hover:border-primary/40"
                            >
                                <PanelHeader title={report.title} />
                                <p className="p-3 text-sm text-muted-foreground">
                                    {ABOUT[report.key]}
                                </p>
                            </Link>
                        ))}
                    </div>
                </div>
            )}
        </>
    );
}
