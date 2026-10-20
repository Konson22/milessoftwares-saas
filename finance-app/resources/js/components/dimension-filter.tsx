import { Select } from '@/components/field';
import type { DimensionOptions } from '@/components/lines-editor';

export type DimensionFilterProps = {
    costCentre: string | null;
    project: string | null;
    dimensionOptions: DimensionOptions;
};

/**
 * Cost centre and project selects for a statement's filter (?cost_centre=,
 * ?project=; "none" for lines without one). Nothing shows until some
 * exist.
 */
export function DimensionFilter({
    costCentre,
    project,
    dimensionOptions,
}: DimensionFilterProps) {
    const filters = [
        {
            name: 'cost_centre',
            label: 'Cost centre',
            value: costCentre,
            options: dimensionOptions.costCentres,
        },
        {
            name: 'project',
            label: 'Project',
            value: project,
            options: dimensionOptions.projects,
        },
    ];

    return filters.map(
        (filter) =>
            filter.options.length > 0 && (
                <label
                    key={filter.name}
                    className="grid gap-1 text-xs font-medium text-muted-foreground"
                >
                    {filter.label}
                    <Select
                        name={filter.name}
                        defaultValue={filter.value ?? ''}
                        className="w-48"
                    >
                        <option value="">All</option>
                        <option value="none">
                            No {filter.label.toLowerCase()}
                        </option>
                        {filter.options.map((option) => (
                            <option key={option.id} value={option.id}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                </label>
            ),
    );
}

/** The chosen filter, in words, for a statement's heading. */
export function dimensionFilterLabel({
    costCentre,
    project,
    dimensionOptions,
}: DimensionFilterProps): string | null {
    const describe = (
        value: string | null,
        options: { id: string; label: string }[],
        noun: string,
    ) =>
        value === null
            ? null
            : value === 'none'
              ? `no ${noun}`
              : (options.find((option) => option.id === value)?.label ?? null);

    const parts = [
        describe(costCentre, dimensionOptions.costCentres, 'cost centre'),
        describe(project, dimensionOptions.projects, 'project'),
    ].filter((part): part is string => part !== null);

    return parts.length === 0 ? null : parts.join(', ');
}
