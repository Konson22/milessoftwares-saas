/**
 * Shown to a teacher whose email matches no active member of staff: they
 * take registers and enter marks only for the classes they teach.
 */
export function UnlinkedNotice({ what }: { what: string }) {
    return (
        <p className="mb-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
            No member of staff has your email address, so there are no classes
            for you to {what} yet. Ask the school office to add you under Staff
            with this email, then assign you as class teacher or to the subjects
            you teach.
        </p>
    );
}
