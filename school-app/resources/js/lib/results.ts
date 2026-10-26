/** A student's result in one subject for a term (App\Support\Results). */
export type SubjectResult = {
    subjectId: string;
    code: string;
    name: string;
    credits: number | null;
    /** Score per assessment id, null while unmarked. */
    scores: Record<string, number | null>;
    mark: number | null;
    complete: boolean;
    grade: string | null;
    points: number | null;
    remark: string | null;
};

/** A student's term results. */
export type StudentResult = {
    studentId: string;
    number: string;
    name: string;
    subjects: SubjectResult[];
    total: number | null;
    average: number | null;
    gpa: number | null;
    credits: number;
    position: number | null;
};

export type AssessmentColumn = { id: string; name: string; weight: number };

/** 81 → "81", 80.5 → "80.5", null → "—". */
export function score(value: number | null): string {
    return value === null ? '—' : String(Number(value.toFixed(1)));
}

/** 1 → "1st", 22 → "22nd". */
export function ordinal(position: number): string {
    const tens = position % 100;
    const suffix =
        tens >= 11 && tens <= 13
            ? 'th'
            : ({ 1: 'st', 2: 'nd', 3: 'rd' }[position % 10] ?? 'th');

    return `${position}${suffix}`;
}
