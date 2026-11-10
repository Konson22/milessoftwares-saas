import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { search } from '@/routes';

type Props = {
    term: string;
    minimum: number;
    groups: {
        title: string;
        results: {
            id: string;
            label: string;
            hint: string | null;
            url: string;
        }[];
    }[];
};

/**
 * Search across the organization: only what you may already open.
 */
export default function SearchPage({ term, minimum, groups }: Props) {
    const searched = term.length >= minimum;

    return (
        <>
            <Head title={term ? `Search: ${term}` : 'Search'} />
            <PageHeader
                title="Search"
                description="Projects, tasks, people, spending, grants and funders you have access to."
            />

            <Form
                {...search.form()}
                className="mb-4 flex max-w-xl items-center gap-2"
            >
                <Input
                    name="q"
                    type="search"
                    aria-label="Search for"
                    placeholder="Search for…"
                    defaultValue={term}
                    autoFocus
                />
                <Button type="submit">Search</Button>
            </Form>

            {!searched && term !== '' && (
                <p className="text-sm text-muted-foreground">
                    Type at least {minimum} characters.
                </p>
            )}

            {searched && groups.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Nothing matches “{term}”.
                </p>
            )}

            <div className="grid gap-4">
                {groups.map((group) => (
                    <Panel key={group.title}>
                        <PanelHeader title={group.title} />
                        <PanelBody>
                            <ul className="grid gap-2 text-sm">
                                {group.results.map((result) => (
                                    <li key={result.id}>
                                        <Link
                                            href={result.url}
                                            className="underline"
                                        >
                                            {result.label}
                                        </Link>
                                        {result.hint && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {result.hint}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </PanelBody>
                    </Panel>
                ))}
            </div>
        </>
    );
}
