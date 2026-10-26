import { Form, Head } from '@inertiajs/react';
import { BookOpenCheck, Building, GraduationCap, School } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { cn } from '@miles/ui/lib/utils';
import { Field, invalid } from '@/components/field';
import { store } from '@/routes/setup';

type Category = { value: string; label: string; description: string };

type Props = {
    categories: Category[];
    defaults: { name: string; startsOn: string; endsOn: string };
};

const ICONS: Record<string, LucideIcon> = {
    primary: School,
    institute: BookOpenCheck,
    college: Building,
    university: GraduationCap,
};

/**
 * Setting the school up once: its category, which decides its words,
 * structure and grading, and its first academic year.
 */
export default function Setup({ categories, defaults }: Props) {
    const [category, setCategory] = useState('primary');
    const terms = category === 'primary' ? 'three terms' : 'two semesters';

    return (
        <>
            <Head title="Set up the school" />
            <PageHeader
                title="Set up the school"
                description="What kind of school is this? It decides how classes, subjects, results and the calendar work."
            />

            <Form {...store.form()} className="grid max-w-3xl gap-4">
                {({ errors, processing }) => (
                    <>
                        <fieldset className="grid gap-3 sm:grid-cols-2">
                            <legend className="sr-only">Category</legend>
                            {categories.map((option) => {
                                const Icon = ICONS[option.value] ?? School;
                                const chosen = option.value === category;

                                return (
                                    <label
                                        key={option.value}
                                        className={cn(
                                            'flex cursor-pointer gap-3 border bg-card p-4 transition-colors hover:border-primary/60',
                                            chosen &&
                                                'border-primary ring-2 ring-primary/30',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="category"
                                            value={option.value}
                                            checked={chosen}
                                            onChange={() =>
                                                setCategory(option.value)
                                            }
                                            className="sr-only"
                                        />
                                        <span className="grid size-9 shrink-0 place-items-center bg-primary/10 text-primary">
                                            <Icon
                                                className="size-5"
                                                aria-hidden
                                            />
                                        </span>
                                        <span>
                                            <span className="block font-medium">
                                                {option.label}
                                            </span>
                                            <span className="block text-sm text-muted-foreground">
                                                {option.description}
                                            </span>
                                        </span>
                                    </label>
                                );
                            })}
                        </fieldset>
                        {errors.category && (
                            <p className="text-sm text-destructive">
                                {errors.category}
                            </p>
                        )}

                        <Panel>
                            <PanelHeader
                                title="First academic year"
                                description={`Split evenly into ${terms}; you can change their dates later.`}
                            />
                            <PanelBody className="grid gap-4 sm:grid-cols-3">
                                <Field
                                    id="name"
                                    label="Name"
                                    error={errors.name}
                                >
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        defaultValue={defaults.name}
                                        placeholder="2026 or 2026/2027"
                                        {...invalid('name', errors.name)}
                                    />
                                </Field>
                                <Field
                                    id="starts_on"
                                    label="Starts"
                                    error={errors.starts_on}
                                >
                                    <Input
                                        id="starts_on"
                                        name="starts_on"
                                        type="date"
                                        required
                                        defaultValue={defaults.startsOn}
                                        {...invalid(
                                            'starts_on',
                                            errors.starts_on,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="ends_on"
                                    label="Ends"
                                    error={errors.ends_on}
                                >
                                    <Input
                                        id="ends_on"
                                        name="ends_on"
                                        type="date"
                                        required
                                        defaultValue={defaults.endsOn}
                                        {...invalid('ends_on', errors.ends_on)}
                                    />
                                </Field>
                                {category === 'primary' && (
                                    <label className="flex items-center gap-2 text-sm sm:col-span-3">
                                        <input
                                            type="hidden"
                                            name="with_defaults"
                                            value="0"
                                        />
                                        <input
                                            type="checkbox"
                                            name="with_defaults"
                                            value="1"
                                            defaultChecked
                                        />
                                        Start with Primary 1 to 8 and the common
                                        subjects (English, Mathematics, Science,
                                        Social Studies, Religious Education,
                                        Arabic)
                                    </label>
                                )}
                            </PanelBody>
                        </Panel>

                        <div>
                            <Button type="submit" disabled={processing}>
                                Set up the school
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}
