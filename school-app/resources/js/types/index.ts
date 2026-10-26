/**
 * What kind of school this is: its words and switches
 * (App\Enums\SchoolCategory::toArray), and the term it is in.
 */
export type School = {
    value: 'primary' | 'institute' | 'college' | 'university';
    label: string;
    /** "Term" or "Semester". */
    period: string;
    /** "Class" or "Year of study". */
    level: string;
    /** "Subject", "Module" or "Course". */
    subject: string;
    /** "Teacher", "Instructor" or "Lecturer". */
    staff: string;
    /** "Admission number" or "Registration number". */
    studentNumber: string;
    usesProgrammes: boolean;
    usesDepartments: boolean;
    usesFaculties: boolean;
    usesCredits: boolean;
    attendanceBySubject: boolean;
    ranksInClass: boolean;
    maxLevel: number;
    term: { id: string; title: string } | null;
};

/**
 * Props every page receives (app/Http/Middleware/HandleInertiaRequests.php).
 */
export type SharedProps = {
    product: { name: string; code: string };
    workspace: { slug: string; name: string; url: string } | null;
    /** Null until the school is set up. */
    school: School | null;
    /** The workspace currency (ISO code); amounts arrive in minor units. */
    currency: string | null;
    user: { name: string; email: string } | null;
    /** For showing and hiding UI only: the server authorizes every action. */
    access: { isOwner: boolean; permissions: string[] } | null;
    flash: { success: string | null };
};

/** A choice in a select. */
export type Option = { value: string; label: string };

/** A band of the grading scale. */
export type GradeBand = {
    grade: string;
    min: number;
    points: number | null;
    remark: string;
};
