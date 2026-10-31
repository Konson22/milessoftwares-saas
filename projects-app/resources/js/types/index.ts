/**
 * Props every page receives (app/Http/Middleware/HandleInertiaRequests.php).
 */
export type SharedProps = {
    product: { name: string; code: string };
    workspace: { slug: string; name: string; url: string } | null;
    /** The workspace currency (ISO code); amounts arrive in minor units. */
    currency: string | null;
    user: { name: string; email: string } | null;
    /** For showing and hiding UI only: the server authorizes every action. */
    access: { isOwner: boolean; permissions: string[] } | null;
    flash: { success: string | null };
};

/** A choice in a select. */
export type Option = { value: string; label: string };

/** Above every project page (App\Http\Controllers\Concerns\ShowsProject). */
export type ProjectHeader = {
    id: string;
    code: string;
    name: string;
    client: string | null;
    status: string;
    open: boolean;
    manager: string | null;
    startOn: string | null;
    dueOn: string | null;
    currency: string;
    progress: number;
    health: string;
    healthReasons: string[];
    can: {
        manage: boolean;
        tasks: boolean;
        logTime: boolean;
        finance: boolean;
    };
};

/** A task as boards and lists show it (TaskController::card). */
export type TaskCard = {
    id: string;
    number: string;
    title: string;
    status: string;
    priority: string;
    assignee: string | null;
    milestone: string | null;
    startOn: string | null;
    dueOn: string | null;
    overdue: boolean;
    estimateHours: number | null;
    blocked: boolean;
    comments: number;
};

/** People, milestones and priorities to choose from on a project's task forms. */
export type TaskChoices = {
    people: Option[];
    milestones: Option[];
    priorities: Option[];
};
