import {
    BadgeCheck,
    Bell,
    Building2,
    CalendarDays,
    CalendarRange,
    ClipboardList,
    Clock,
    Database,
    FileText,
    Gauge,
    HandCoins,
    History,
    IdCard,
    LayoutDashboard,
    ListChecks,
    Palette,
    Plug,
    Receipt,
    Settings,
    Shield,
    Target,
    TrendingUp,
    TriangleAlert,
    UserPlus,
    Users,
    UsersRound,
    Wallet,
    Wallet2,
} from 'lucide-react';

/**
 * Sidebar navigation for Core Transaction 2 — HRIS.
 *
 * Each group carries an optional uppercase label; each item may carry
 * `children` (rendered as a dropdown), a `badge`, and `roles` (omit = visible to
 * every authenticated role).
 */
export const NAV_GROUPS = [
    {
        label: null,
        items: [
            {
                id: 'dashboard',
                label: 'Dashboard',
                icon: LayoutDashboard,
                href: '/dashboard',
            },
        ],
    },
    {
        label: 'Human Resource',
        items: [
            {
                id: 'employee-info',
                label: 'Employee Information',
                icon: IdCard,
                children: [
                    {
                        id: 'employee-list',
                        label: 'Employee Directory',
                        icon: Users,
                        href: '/hr/employees',
                    },
                    {
                        id: 'employee-create',
                        label: 'Add Employee',
                        icon: UserPlus,
                        href: '/hr/employees/create',
                        roles: ['admin', 'hr_staff'],
                    },
                ],
            },
            {
                id: 'timekeeping',
                label: 'Timekeeping & Attendance',
                icon: Clock,
                children: [
                    {
                        id: 'tk-daily',
                        label: 'Daily Records',
                        icon: ListChecks,
                        href: '/hr/timekeeping',
                    },
                    {
                        id: 'tk-overtime',
                        label: 'Overtime',
                        icon: Clock,
                        href: '/hr/timekeeping/overtime',
                    },
                    {
                        id: 'tk-schedules',
                        label: 'Shifts & Schedules',
                        icon: CalendarRange,
                        href: '/hr/timekeeping/schedules',
                    },
                    {
                        id: 'tk-reports',
                        label: 'Reports',
                        icon: Gauge,
                        href: '/hr/timekeeping/reports',
                    },
                    {
                        id: 'tk-exceptions',
                        label: 'Exceptions',
                        icon: TriangleAlert,
                        href: '/hr/timekeeping/exceptions',
                    },
                    {
                        id: 'tk-history',
                        label: 'History',
                        icon: History,
                        href: '/hr/timekeeping/history',
                        roles: ['admin', 'hr_staff'],
                    },
                ],
            },
            {
                id: 'leave',
                label: 'Leave & Absence',
                icon: CalendarDays,
                children: [
                    {
                        id: 'leave-requests',
                        label: 'Requests',
                        icon: ClipboardList,
                        href: '/hr/leave',
                    },
                    {
                        id: 'leave-calendar',
                        label: 'Calendar',
                        icon: CalendarDays,
                        href: '/hr/leave/calendar',
                    },
                    {
                        id: 'leave-balances',
                        label: 'Balances',
                        icon: Wallet2,
                        href: '/hr/leave/balances',
                    },
                    {
                        id: 'leave-types',
                        label: 'Leave Types',
                        icon: FileText,
                        href: '/hr/leave/types',
                    },
                ],
            },
            {
                id: 'payroll',
                label: 'Payroll & Compensation',
                icon: Wallet,
                children: [
                    {
                        id: 'payroll-runs',
                        label: 'Payroll Runs',
                        icon: Receipt,
                        href: '/hr/payroll',
                        roles: ['admin', 'hr_staff'],
                    },
                    {
                        id: 'payroll-payslips',
                        label: 'Payslips',
                        icon: FileText,
                        href: '/hr/payroll/payslips',
                    },
                    {
                        id: 'payroll-compensation',
                        label: 'Allowances & Loans',
                        icon: HandCoins,
                        href: '/hr/payroll/compensation',
                        roles: ['admin', 'hr_staff'],
                    },
                ],
            },
            {
                id: 'performance',
                label: 'Performance Management',
                icon: TrendingUp,
                children: [
                    {
                        id: 'perf-reviews',
                        label: 'Evaluations',
                        icon: BadgeCheck,
                        href: '/hr/performance',
                    },
                    {
                        id: 'perf-cycles',
                        label: 'Review Cycles',
                        icon: CalendarRange,
                        href: '/hr/performance/cycles',
                    },
                    {
                        id: 'perf-kpis',
                        label: 'KPI Library',
                        icon: Target,
                        href: '/hr/performance/kpis',
                    },
                ],
            },
        ],
    },
    {
        label: 'System',
        items: [
            {
                id: 'settings',
                label: 'Settings',
                icon: Settings,
                children: [
                    {
                        id: 'set-general',
                        label: 'General',
                        icon: Settings,
                        href: '/settings/general',
                        roles: ['admin'],
                    },
                    {
                        id: 'set-appearance',
                        label: 'Appearance',
                        icon: Palette,
                        href: '/settings/appearance',
                    },
                    {
                        id: 'set-organization',
                        label: 'Organization',
                        icon: Building2,
                        href: '/settings/organization',
                        roles: ['admin', 'hr_staff'],
                    },
                    {
                        id: 'set-notifications',
                        label: 'Notifications',
                        icon: Bell,
                        href: '/settings/notifications',
                        roles: ['admin'],
                    },
                    {
                        id: 'set-users',
                        label: 'Users & Access',
                        icon: UsersRound,
                        href: '/settings/users',
                        roles: ['admin'],
                    },
                    {
                        id: 'set-security',
                        label: 'Security',
                        icon: Shield,
                        href: '/settings/security',
                    },
                    {
                        id: 'set-data',
                        label: 'Data & Backup',
                        icon: Database,
                        href: '/settings/data',
                        roles: ['admin'],
                    },
                    {
                        id: 'set-integrations',
                        label: 'Integrations',
                        icon: Plug,
                        href: '/settings/integrations',
                        roles: ['admin'],
                    },
                ],
            },
        ],
    },
];

/** Strips the query string and hash, leaving a comparable path. */
function pathOf(url) {
    return url.split('?')[0].split('#')[0];
}

/**
 * Every navigable href, longest first.
 *
 * Matching by longest prefix is what lets `/hr/employees/create` win over
 * `/hr/employees`, while `/hr/employees/42` still resolves to the directory.
 */
const ALL_HREFS = NAV_GROUPS.flatMap((group) =>
    group.items.flatMap((item) => [
        ...(item.href ? [item.href] : []),
        ...(item.children ?? []).map((child) => child.href),
    ]),
).sort((a, b) => b.length - a.length);

/** The single nav href that best describes the current URL. */
export function bestMatch(currentUrl) {
    const path = pathOf(currentUrl);

    return ALL_HREFS.find((href) => path === href || path.startsWith(`${href}/`)) ?? null;
}

/** True when this href is the best match for the current URL. */
export function isHrefActive(href, currentUrl) {
    if (!href) return false;

    return bestMatch(currentUrl) === href;
}

/** True when any of the item's children owns the current URL. */
export function isItemActive(item, currentUrl) {
    if (item.href && isHrefActive(item.href, currentUrl)) return true;

    return (item.children ?? []).some((child) => isHrefActive(child.href, currentUrl));
}

export function visibleGroups(groups, role) {
    const allowed = (entry) => !entry.roles || entry.roles.includes(role);

    return groups
        .map((group) => ({
            ...group,
            items: group.items
                .filter(allowed)
                .map((item) => ({
                    ...item,
                    children: item.children?.filter(allowed),
                }))
                // A parent whose children are all hidden has nothing to show.
                .filter((item) => item.href || (item.children?.length ?? 0) > 0),
        }))
        .filter((group) => group.items.length > 0);
}
