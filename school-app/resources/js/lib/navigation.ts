import {
    BookOpen,
    Building2,
    CalendarCheck,
    ClipboardList,
    FileSpreadsheet,
    GraduationCap,
    Inbox,
    LayoutDashboard,
    PenLine,
    ReceiptText,
    Settings,
    UserRound,
    Users,
    Wallet,
} from 'lucide-react';
import type { ShellNavItem } from '@miles/ui/shell/navigation';
import { home } from '@/routes';
import { index as applications } from '@/routes/applications';
import { index as attendance } from '@/routes/attendance';
import { index as classes } from '@/routes/classes';
import { index as departments } from '@/routes/departments';
import { index as fees } from '@/routes/fees';
import { index as invoices } from '@/routes/invoices';
import { index as marks } from '@/routes/marks';
import { index as programmes } from '@/routes/programmes';
import { index as results } from '@/routes/results';
import { index as settings } from '@/routes/settings';
import { index as staff } from '@/routes/staff';
import { index as students } from '@/routes/students';
import { index as subjects } from '@/routes/subjects';
import { plural } from '@/lib/school';
import type { School } from '@/types';

export type NavItem = ShellNavItem & {
    /** Shown to members holding this catalog permission (or owners); null for everyone. */
    permission: string | null;
};

export type NavGroup = { title: string; items: NavItem[] };

/**
 * The product's sections, in menu order, in the school's words: a primary
 * school has classes and subjects, a university faculties, programmes and
 * courses. Before setup, only Home.
 */
export function navigation(school: School | null): NavGroup[] {
    const overview: NavGroup = {
        title: 'Overview',
        items: [
            {
                title: 'Home',
                href: home(),
                icon: LayoutDashboard,
                permission: null,
            },
        ],
    };

    if (school === null) {
        return [overview];
    }

    const subjectTitle = plural(school.subject).replace(/^./, (c) =>
        c.toUpperCase(),
    );

    return [
        overview,
        {
            title: 'Students',
            items: [
                {
                    title: 'Students',
                    href: students(),
                    icon: Users,
                    permission: 'school.students.view',
                },
                {
                    title: 'Admissions',
                    href: applications(),
                    icon: Inbox,
                    permission: 'school.students.view',
                },
                {
                    title: 'Attendance',
                    href: attendance(),
                    icon: CalendarCheck,
                    permission: 'school.students.view',
                },
            ],
        },
        {
            title: 'Academics',
            items: [
                {
                    title: 'Marks',
                    href: marks(),
                    icon: PenLine,
                    permission: 'school.marks.record',
                },
                {
                    title: 'Results',
                    href: results(),
                    icon: FileSpreadsheet,
                    permission: 'school.results.view',
                },
                {
                    title: 'Classes',
                    href: classes(),
                    icon: ClipboardList,
                    permission: 'school.students.view',
                },
                {
                    title: subjectTitle,
                    href: subjects(),
                    icon: BookOpen,
                    permission: 'school.students.view',
                },
                ...(school.usesProgrammes
                    ? [
                          {
                              title: 'Programmes',
                              href: programmes(),
                              icon: GraduationCap,
                              permission: 'school.students.view',
                          },
                      ]
                    : []),
                ...(school.usesDepartments
                    ? [
                          {
                              title: school.usesFaculties
                                  ? 'Faculties & departments'
                                  : 'Departments',
                              href: departments(),
                              icon: Building2,
                              permission: 'school.students.view',
                          },
                      ]
                    : []),
                {
                    title: 'Staff',
                    href: staff(),
                    icon: UserRound,
                    permission: 'school.students.view',
                },
            ],
        },
        {
            title: 'Fees',
            items: [
                {
                    title: 'Fees',
                    href: fees(),
                    icon: Wallet,
                    permission: 'school.fees.view',
                },
                {
                    title: 'Invoices',
                    href: invoices(),
                    icon: ReceiptText,
                    permission: 'school.fees.view',
                },
            ],
        },
        {
            title: 'School',
            items: [
                {
                    title: 'Calendar & settings',
                    href: settings(),
                    icon: Settings,
                    permission: 'school.settings.manage',
                },
            ],
        },
    ];
}

/**
 * The sections the member may use, without empty groups.
 */
export function visibleNavigation(
    school: School | null,
    can: (permission: string) => boolean,
): NavGroup[] {
    return navigation(school)
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) => item.permission === null || can(item.permission),
            ),
        }))
        .filter((group) => group.items.length > 0);
}
