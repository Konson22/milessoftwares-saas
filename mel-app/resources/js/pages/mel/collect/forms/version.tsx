import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { useState } from 'react';
import type { Answers, Definition } from '@/mel/collect/definition';
import { FormRenderer, localToday } from '@/mel/collect/renderer';
import { formatDateTime } from '@/mel/lib/mel';
import { show } from '@/routes/mel/collect/forms';

type Props = {
    form: { id: string; name: string };
    version: {
        id: string;
        number: number;
        note: string | null;
        publishedBy: string;
        publishedAt: string;
        current: boolean;
        definition: Definition;
    };
};

export default function FormVersionPage({ form, version }: Props) {
    const [answers, setAnswers] = useState<Answers>({});

    return (
        <>
            <Head title={`${form.name} · version ${version.number}`} />
            <PageHeader
                title={`${form.name} · version ${version.number}`}
                description={`Published ${formatDateTime(version.publishedAt)} by ${version.publishedBy}. A published version never changes; submissions filled in on it are always shown with it.`}
                badges={
                    version.current ? (
                        <StatusBadge tone="success">In use</StatusBadge>
                    ) : (
                        <StatusBadge tone="neutral">Replaced</StatusBadge>
                    )
                }
                actions={
                    <Button variant="outline" asChild>
                        <Link href={show(form.id)}>The form</Link>
                    </Button>
                }
            />
            {version.note && <p className="mb-3 text-sm">{version.note}</p>}
            <Panel>
                <PanelHeader
                    title="As the phone shows it"
                    description="Try it: nothing is saved."
                />
                <PanelBody>
                    <FormRenderer
                        definition={version.definition}
                        answers={answers}
                        onChange={setAnswers}
                        today={localToday()}
                        showErrors
                    />
                </PanelBody>
            </Panel>
        </>
    );
}
