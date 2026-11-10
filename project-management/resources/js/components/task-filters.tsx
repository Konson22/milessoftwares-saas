import { Form } from '@inertiajs/react';
import { SearchField } from '@miles/ui/components/search-field';
import { Button } from '@miles/ui/components/ui/button';
import { Select } from '@/components/field';
import type { TaskChoices } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

export type TaskFilterValues = {
    search: string;
    assignee: string;
    milestone: string;
};

/**
 * Narrowing a project's tasks by words, assignee or milestone.
 */
export function TaskFilters({
    action,
    filters,
    choices,
}: {
    action: RouteFormDefinition<'get'>;
    filters: TaskFilterValues;
    choices: TaskChoices;
}) {
    return (
        <Form {...action} className="flex flex-wrap gap-2">
            <SearchField
                name="search"
                defaultValue={filters.search}
                placeholder="Number or title"
                aria-label="Search tasks"
            />
            <Select
                name="assignee"
                aria-label="Assignee"
                defaultValue={filters.assignee}
                placeholder="Anyone"
                options={[
                    { value: 'none', label: 'Unassigned' },
                    ...choices.people,
                ]}
                className="w-40"
            />
            {choices.milestones.length > 0 && (
                <Select
                    name="milestone"
                    aria-label="Milestone"
                    defaultValue={filters.milestone}
                    placeholder="All milestones"
                    options={choices.milestones}
                    className="w-40"
                />
            )}
            <Button type="submit" variant="outline">
                Filter
            </Button>
        </Form>
    );
}
