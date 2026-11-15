import { index as members } from '@/routes/admin/members';
import { index as orgUnits } from '@/routes/admin/org-units';
import { index as advances } from '@/routes/advances';
import { index as documents } from '@/routes/documents';
import { index as pettyCash } from '@/routes/petty-cash';
import { index as programmes } from '@/routes/programmes';
import {
    Banknote,
    BarChart3,
    CheckSquare,
    ClipboardList,
    Gauge,
    History,
    ListTree,
    SquarePen,
    Target,
    UserCheck,
    FolderKanban,
    ClipboardCheck,
    Coins,
    FileText,
    Globe,
    HandCoins,
    HandHeart,
    Handshake,
    Megaphone,
    Search,
    Landmark,
    Building2,
    Layers,
    LayoutDashboard,
    Network,
    ScrollText,
    ShoppingCart,
    Settings,
    UserCog,
    Users,
} from 'lucide-react';
import type { ShellNavItem } from '@miles/ui/shell/navigation';
import { approvals, commitments, home, myWork } from '@/routes';
import { index as procurement } from '@/routes/procurement';
import { index as fundingListings } from '@/routes/funding-listings';
import {
    interests as marketplaceInterests,
    organizations as marketplaceOrganizations,
    projects as marketplaceProjects,
} from '@/routes/marketplace';
import { edit as publicProfile } from '@/routes/public-profile';
import { index as donorConnections } from '@/routes/donor-connections';
import { index as fundingInterests } from '@/routes/funding-interests';
import {
    dashboard as fundingDashboard,
    index as beneficiaries,
    projects as fundedProjects,
    reports as donorReports,
    tasks as fundedTasks,
} from '@/routes/beneficiaries';
import { index as donorGrants } from '@/routes/beneficiaries/grants';
import { index as donorPortal } from '@/routes/donor';
import { index as audit } from '@/routes/admin/audit';
import { edit as settings } from '@/routes/admin/settings';
import { index as funders } from '@/routes/funders';
import { index as sharedProjects } from '@/routes/shared-projects';
import { index as grants } from '@/routes/grants';
import { index as people } from '@/routes/people';
import { index as projects } from '@/routes/projects';
import { index as spaces } from '@/routes/structure';
import { home as melHome } from '@/routes/mel';
import { index as melAudit } from '@/routes/mel/audit';
import { index as collectAssignments } from '@/routes/mel/collect/assignments';
import { index as collectForms } from '@/routes/mel/collect/forms';
import { index as melData } from '@/routes/mel/data';
import { index as melFrameworks } from '@/routes/mel/frameworks';
import { index as melIndicators } from '@/routes/mel/indicators';
import { index as melProgrammes } from '@/routes/mel/programmes';
import { index as melReports } from '@/routes/mel/reports';
import { show as melSettings } from '@/routes/mel/settings';
import { index as melVerification } from '@/routes/mel/verification';

/** Who sees M&E's programmes, frameworks and indicators. */
const MEL_PLANS = ['mel.programmes.view', 'mel.programmes.all'];

export type NavItem = ShellNavItem & {
    /** Shown to members holding this permission, or any of these (or owners); null for everyone. */
    permission: string | string[] | null;
    /** Shown only while this part of the product is on. */
    module?: string;
};

export type NavGroup = { title: string; items: NavItem[] };

/**
 * The app's sections, in menu order. Built when rendered (not at import):
 * the links carry the current organization (setUrlDefaults in the layout).
 * Only features that exist are listed.
 */
export function navigation(): NavGroup[] {
    return [
        {
            title: 'Overview',
            items: [
                {
                    title: 'Portfolio',
                    href: home(),
                    icon: LayoutDashboard,
                    permission: null,
                },
                {
                    title: 'My work',
                    href: myWork(),
                    icon: CheckSquare,
                    permission: null,
                },
            ],
        },
        {
            title: 'Projects',
            items: [
                {
                    title: 'Projects',
                    href: projects(),
                    icon: FolderKanban,
                    permission: 'projects.view',
                },
                {
                    title: 'Programmes',
                    href: programmes(),
                    icon: Layers,
                    permission: 'projects.view',
                },
                {
                    title: 'Spaces',
                    href: spaces(),
                    icon: Network,
                    permission: 'projects.view',
                },
                {
                    title: 'People',
                    href: people(),
                    icon: Users,
                    permission: 'projects.view',
                },
                {
                    // Other organizations' projects this one takes part in (ADR-0014).
                    title: 'Shared projects',
                    href: sharedProjects(),
                    icon: Handshake,
                    permission: null,
                },
                {
                    title: 'Procurement',
                    module: 'procurement',
                    href: procurement(),
                    icon: ShoppingCart,
                    permission: ['procurement.view', 'procurement.request'],
                },
            ],
        },
        {
            title: 'Monitoring & evaluation',
            items: [
                {
                    title: 'Results overview',
                    module: 'mel',
                    href: melHome(),
                    icon: Target,
                    permission: [
                        ...MEL_PLANS,
                        'mel.data.view',
                        'mel.collect.view',
                        'mel.reports.view',
                    ],
                },
                {
                    title: 'M&E programmes',
                    module: 'mel',
                    href: melProgrammes(),
                    icon: Layers,
                    permission: MEL_PLANS,
                },
                {
                    title: 'Results frameworks',
                    module: 'mel',
                    href: melFrameworks(),
                    icon: ListTree,
                    permission: MEL_PLANS,
                },
                {
                    title: 'Indicators',
                    module: 'mel',
                    href: melIndicators(),
                    icon: Gauge,
                    permission: MEL_PLANS,
                },
                {
                    title: 'Data entry',
                    module: 'mel',
                    href: melData(),
                    icon: SquarePen,
                    permission: 'mel.data.view',
                },
                {
                    title: 'Verification',
                    module: 'mel',
                    href: melVerification(),
                    icon: ClipboardCheck,
                    permission: 'mel.data.review',
                },
                {
                    title: 'Forms & surveys',
                    module: 'mel',
                    href: collectForms(),
                    icon: ClipboardList,
                    permission: 'mel.collect.view',
                },
                {
                    title: 'Field assignments',
                    module: 'mel',
                    href: collectAssignments(),
                    icon: UserCheck,
                    permission: 'mel.collect.view',
                },
                {
                    title: 'M&E reports',
                    module: 'mel',
                    href: melReports(),
                    icon: BarChart3,
                    permission: 'mel.reports.view',
                },
            ],
        },
        {
            title: 'Funding',
            items: [
                {
                    title: 'Grants',
                    module: 'grants',
                    href: grants(),
                    icon: HandCoins,
                    permission: 'grants.view',
                },
                {
                    title: 'Funders',
                    module: 'grants',
                    href: funders(),
                    icon: Landmark,
                    permission: 'grants.view',
                },
                {
                    title: 'Public profile',
                    href: publicProfile(),
                    icon: Globe,
                    permission: 'marketplace.publish',
                },
                {
                    title: 'Funding listings',
                    href: fundingListings(),
                    icon: Megaphone,
                    permission: 'marketplace.publish',
                },
                {
                    title: 'Expressions of interest',
                    href: fundingInterests(),
                    icon: HandHeart,
                    permission: 'marketplace.publish',
                },
                {
                    title: 'Connected donors',
                    href: donorConnections(),
                    icon: Handshake,
                    permission: 'organization.manage',
                },
            ],
        },
        {
            title: 'Finance',
            items: [
                {
                    title: 'Approvals',
                    href: approvals(),
                    icon: ClipboardCheck,
                    permission: [
                        'finance.budgets.approve',
                        'finance.expenses.approve',
                        'finance.payments.record',
                    ],
                },
                {
                    title: 'Commitments',
                    href: commitments(),
                    icon: Banknote,
                    permission: 'finance.commitments.view',
                },
                {
                    title: 'Staff advances',
                    module: 'accounting',
                    href: advances(),
                    icon: HandCoins,
                    permission: [
                        'finance.advances.request',
                        'finance.advances.view',
                    ],
                },
                {
                    title: 'Petty cash',
                    module: 'accounting',
                    href: pettyCash(),
                    icon: Coins,
                    permission: 'finance.pettycash.view',
                },
            ],
        },
        {
            title: 'Records',
            items: [
                {
                    title: 'Documents',
                    href: documents(),
                    icon: FileText,
                    permission: 'documents.view',
                    module: 'documents',
                },
            ],
        },
        {
            title: 'Administration',
            items: [
                {
                    title: 'Members',
                    href: members(),
                    icon: UserCog,
                    permission: 'organization.manage',
                },
                {
                    title: 'Departments and teams',
                    href: orgUnits(),
                    icon: Building2,
                    permission: 'organization.manage',
                },
                {
                    title: 'Settings',
                    href: settings(),
                    icon: Settings,
                    permission: 'organization.manage',
                },
                {
                    title: 'Audit log',
                    href: audit(),
                    icon: ScrollText,
                    permission: 'organization.audit.view',
                },
                {
                    title: 'M&E settings',
                    module: 'mel',
                    href: melSettings(),
                    icon: Settings,
                    permission: 'mel.settings.manage',
                },
                {
                    title: 'M&E audit trail',
                    module: 'mel',
                    href: melAudit(),
                    icon: History,
                    permission: 'mel.audit.view',
                },
            ],
        },
    ];
}

export type AccountType = 'funder' | 'implementer' | null;

/**
 * A funder's menu: its funding dashboard and its own work, who it funds and
 * finding more, its records and administration. None of an implementer's
 * project, incoming-funding or finance sections.
 */
function funderNavigation(): NavGroup[] {
    const sections = navigation();
    const keep = (title: string) =>
        sections.filter((group) => group.title === title);

    return [
        {
            title: 'Overview',
            items: [
                {
                    title: 'Funding dashboard',
                    href: fundingDashboard(),
                    icon: LayoutDashboard,
                    permission: null,
                },
                {
                    title: 'My work',
                    href: myWork(),
                    icon: CheckSquare,
                    permission: null,
                },
                {
                    title: 'Shared projects',
                    href: sharedProjects(),
                    icon: Handshake,
                    permission: null,
                },
            ],
        },
        ...fundingNavigation(),
        ...keep('Records'),
        ...keep('Administration'),
    ];
}

/**
 * For an organization that funds others: who it funds, and finding more.
 */
function fundingNavigation(): NavGroup[] {
    return [
        {
            title: 'Funding others',
            items: [
                {
                    title: 'Beneficiaries',
                    href: beneficiaries(),
                    icon: Handshake,
                    permission: 'grants.view',
                },
                {
                    title: 'Grants and payments',
                    href: donorGrants(),
                    icon: HandCoins,
                    permission: 'grants.view',
                },
                {
                    title: 'Their reports',
                    href: donorReports(),
                    icon: FileText,
                    permission: 'grants.view',
                },
                {
                    title: 'Funded projects',
                    href: fundedProjects(),
                    icon: FolderKanban,
                    permission: 'grants.view',
                },
                {
                    title: 'Funded tasks',
                    href: fundedTasks(),
                    icon: CheckSquare,
                    permission: 'grants.view',
                },
            ],
        },
        {
            title: 'Marketplace',
            items: [
                {
                    title: 'Discover organizations',
                    href: marketplaceOrganizations(),
                    icon: Search,
                    permission: 'grants.view',
                },
                {
                    title: 'Projects seeking funding',
                    href: marketplaceProjects(),
                    icon: Megaphone,
                    permission: 'grants.view',
                },
                {
                    title: 'Interests',
                    href: marketplaceInterests(),
                    icon: HandHeart,
                    permission: 'grants.view',
                },
            ],
        },
    ];
}

/**
 * The sections the member may use, without empty groups: a funder's menu or
 * an implementer's, by the organization's account type.
 */
export function visibleNavigation(
    can: (permission: string) => boolean,
    donor: { confined: boolean } | null = null,
    accountType: AccountType = null,
    modules: string[] = [],
): NavGroup[] {
    const portal: NavGroup = {
        title: 'Donor portal',
        items: [
            {
                title: 'Your grants',
                module: 'grants',
                href: donorPortal(),
                icon: HandCoins,
                permission: null,
            },
        ],
    };
    // Someone who only acts for a funder has nothing else to go to.
    if (donor?.confined) {
        return [portal];
    }

    const sections = [
        ...(donor ? [portal] : []),
        ...(accountType === 'funder' ? funderNavigation() : navigation()),
    ];

    return sections
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) =>
                    (item.module === undefined ||
                        modules.includes(item.module)) &&
                    (item.permission === null ||
                        [item.permission].flat().some(can)),
            ),
        }))
        .filter((group) => group.items.length > 0);
}
