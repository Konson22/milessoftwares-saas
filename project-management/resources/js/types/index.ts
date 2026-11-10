/**
 * Props every page receives (app/Http/Middleware/HandleInertiaRequests.php).
 */
export type SharedProps = {
    /** The Miles Softwares website (url). */
    product: { name: string; url: string };
    /** The organization of this page, null outside one. */
    organization: {
        slug: string;
        name: string;
        url: string;
        /** The workspace on the platform: its home, and members and roles. */
        workspaceUrl: string;
        membersUrl: string;
        /** It funds others: the funding sections show in its menu. */
        funds: boolean;
        /** The parts of the product that are on (App\Support\Modules). */
        modules: string[];
    } | null;
    /** Workspaces the user has opened Projects in (the switcher). */
    organizations: { slug: string; name: string; url: string }[];
    /** The organization currency (ISO code); amounts arrive in minor units. */
    currency: string | null;
    user: { name: string; email: string } | null;
    donorPortal: { confined: boolean } | null;
    /** For showing and hiding UI only: the server authorizes every action. */
    access: { isOwner: boolean; permissions: string[] } | null;
    /** Accounting pages: other currencies and fixed assets are available. */
    multicurrency?: boolean;
    fixedAssets?: boolean;
    /** The bell: unread notifications in this organization. */
    unreadNotifications: number;
    flash: {
        success: string | null;
        warning: string | null;
    };
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
    programme: string | null;
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
        requestSpending: boolean;
    };
};

/** A task as boards and lists show it (TaskController::card). */
export type TaskCard = {
    id: string;
    number: string;
    title: string;
    status: string;
    listStatus: string | null;
    priority: string;
    assignee: string | null;
    milestone: string | null;
    startOn: string | null;
    dueOn: string | null;
    overdue: boolean;
    estimateHours: number | null;
    blocked: boolean;
    comments: number;
    isSubtask: boolean;
    subtasks: { total: number; done: number };
    checklist: { total: number; done: number };
};

/** People, milestones and priorities to choose from on a project's task forms. */
export type TaskChoices = {
    people: Option[];
    milestones: Option[];
    priorities: Option[];
};
