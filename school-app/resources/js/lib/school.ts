import { usePage } from '@inertiajs/react';
import type { School } from '@/types';

/**
 * The school's category: its words ("Term", "Course"...) and switches.
 * Pages after setup always have it.
 */
export function useSchool(): School {
    const { school } = usePage().props;

    if (school === null) {
        throw new Error('The school is not set up.');
    }

    return school;
}

/** "Term" → "terms", "Course" → "courses". */
export function plural(word: string): string {
    return `${word.toLowerCase()}s`;
}
