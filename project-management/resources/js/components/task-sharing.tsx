import { Form, Link } from '@inertiajs/react';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Select } from '@/components/field';
import type { Option } from '@/types';

export type TaskSharingProps = {
    shared: boolean;
    participantId: string | null;
    givenTo: string | null;
    givenToActive: boolean;
    participants: Option[];
    canChange: boolean;
    url: string;
    view: string | null;
};

/**
 * Who outside the organization sees a task: nobody (internal), every
 * organization taking part, or one of them that works on it.
 */
export function TaskSharing({ sharing }: { sharing: TaskSharingProps }) {
    const current = sharing.participantId
        ? `give:${sharing.participantId}`
        : sharing.shared
          ? 'shared'
          : 'internal';

    return (
        <Panel>
            <PanelHeader
                title="Partners"
                description="Internal tasks stay with your organization. Shared and given tasks show their comments and files to those organizations."
            />
            <PanelBody className="grid gap-3 text-sm">
                <p>
                    {sharing.givenTo
                        ? `Given to ${sharing.givenTo}${sharing.shared ? ', and shared with all partners' : ''}.`
                        : sharing.shared
                          ? 'Shared with every partner on the project.'
                          : 'Internal: no partner sees it.'}
                </p>
                {sharing.givenTo && !sharing.givenToActive && (
                    <p className="text-destructive">
                        {sharing.givenTo} no longer takes part: give the task to
                        someone else, or keep it internal.
                    </p>
                )}
                {sharing.canChange && (
                    <Form
                        action={sharing.url}
                        method="put"
                        options={{ preserveScroll: true }}
                        transform={(data) => {
                            const choice = String(data.choice ?? 'internal');

                            return {
                                shared: choice === 'shared' ? '1' : '0',
                                participant_id: choice.startsWith('give:')
                                    ? choice.slice(5)
                                    : null,
                            };
                        }}
                        className="flex flex-wrap items-end gap-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Select
                                    id="sharing-choice"
                                    name="choice"
                                    defaultValue={current}
                                    options={[
                                        {
                                            value: 'internal',
                                            label: 'Internal',
                                        },
                                        {
                                            value: 'shared',
                                            label: 'Shared with all partners',
                                        },
                                        ...sharing.participants.map(
                                            (option) => ({
                                                value: `give:${option.value}`,
                                                label: `Given to ${option.label}`,
                                            }),
                                        ),
                                    ]}
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Save
                                </Button>
                                {errors.participant_id && (
                                    <p className="w-full text-destructive">
                                        {errors.participant_id}
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                )}
                {sharing.view && (
                    <Link href={sharing.view} className="underline">
                        See it as partners do
                    </Link>
                )}
            </PanelBody>
        </Panel>
    );
}
