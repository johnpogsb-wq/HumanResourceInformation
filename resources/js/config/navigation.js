import {
    BadgeCheck,
    Banknote,
    Bell,
    Briefcase,
    Building2,
    CalendarDays,
    CalendarRange,
    ClipboardList,
    Clock,
    Database,
    DoorOpen,
    FileText,
    FileWarning,
    Gauge,
    HandCoins,
    Handshake,
    History,
    IdCard,
    Inbox,
    LayoutDashboard,
    ListChecks,
    Palette,
    Plug,
    Receipt,
    Settings,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Target,
    TrendingUp,
    TriangleAlert,
    Users,
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
        label: 'Employee Management',
        items: [
            {
                id: 'employee-info',
                label: 'Employee Information',
                icon: IdCard,
                children: [
                    {
                        /*
                         * The way into the workforce, so it sits above the
                         * directory rather than under it.
                         *
                         * PrimePower does not hire into this system directly:
                         * Core 1 recruits, sends the hire over the API, and
                         * somebody here approves or declines it. That makes
                         * this a work queue rather than a record screen, which
                         * is why it is the only nav entry carrying a count.
                         */
                        id: 'employee-endorsements',
                        label: 'New Hires',
                        icon: Inbox,
                        href: '/hr/endorsements',
                        roles: ['admin', 'hr_staff'],
                        // Filled from the shared prop of this name. Hidden at
                        // zero — an always-lit badge stops being read, the
                        // same rule the credential indicator follows.
                        badgeKey: 'pendingEndorsements',
                    },
                    {
                        id: 'employee-list',
                        label: 'Employee Directory',
                        icon: Users,
                        href: '/hr/employees',
                    },
                    // Master data. HR maintains the org structure while filing
                    // people, so it sits with the records rather than under
                    // Settings, where it used to live.
                    {
                        id: 'employee-clients',
                        label: 'Clients',
                        icon: Handshake,
                        href: '/hr/clients',
                        roles: ['admin', 'hr_staff'],
                    },
                    {
                        id: 'employee-departments',
                        label: 'Departments',
                        icon: Building2,
                        href: '/hr/departments',
                        roles: ['admin', 'hr_staff'],
                    },
                    {
                        id: 'employee-positions',
                        label: 'Positions',
                        icon: Briefcase,
                        href: '/hr/positions',
                        roles: ['admin', 'hr_staff'],
                    },
                ],
            },
        ],
    },
    {
        label: 'Time & Attendance',
        items: [
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
                        id: 'tk-holidays',
                        label: 'Holidays',
                        icon: CalendarDays,
                        href: '/hr/timekeeping/holidays',
                    },
                    {
                        id: 'tk-reports',
                        label: 'Reports',
                        icon: Gauge,
                        href: '/hr/timekeeping/reports',
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
        ],
    },
    {
        label: 'Payroll & Performance',
        items: [
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
                        id: 'payroll-salaries',
                        label: 'Salaries & Adjustments',
                        icon: Banknote,
                        href: '/hr/payroll/salaries',
                        roles: ['admin', 'hr_staff'],
                    },
                    {
                        id: 'payroll-compensation',
                        label: 'Allowances & Loans',
                        icon: HandCoins,
                        href: '/hr/payroll/compensation',
                        roles: ['admin', 'hr_staff'],
                    },
                    {
                        id: 'payroll-separations',
                        label: 'Separation & Final Pay',
                        icon: DoorOpen,
                        href: '/hr/payroll/separations',
                        roles: ['admin', 'hr_staff'],
                    },
                    {
                        id: 'payroll-compliance',
                        label: 'Compliance',
                        icon: ShieldCheck,
                        href: '/hr/payroll/compliance',
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
    /*
     * The screens that read across modules rather than maintaining one.
     *
     * Everything else in the sidebar is a place records are *kept*; these are
     * places records are *judged*. Each one runs a config-driven rule engine
     * over data that already exists somewhere else — Credentials over document
     * expiry, 201 File Status over what was never filed, Exceptions over the
     * DTR, and Deployment Readiness over all three at once. None of them owns
     * a table.
     *
     * That is also why they were scattered before: Credentials and 201 File
     * Status sat under Employee Information and Exceptions under Timekeeping,
     * as though each belonged to the module it happened to read from. Grouping
     * them says what they actually are.
     */
    {
        label: 'AI & Analytics',
        items: [
            {
                id: 'analytics-deployment',
                label: 'Deployment Readiness',
                icon: ShieldCheck,
                href: '/hr/deployment',
            },
            {
                id: 'analytics-credentials',
                label: 'Credentials',
                icon: ShieldAlert,
                href: '/hr/credentials',
            },
            {
                id: 'analytics-onboarding',
                label: '201 File Status',
                icon: FileWarning,
                href: '/hr/onboarding',
            },
            {
                id: 'analytics-exceptions',
                label: 'Attendance Exceptions',
                icon: TriangleAlert,
                href: '/hr/timekeeping/exceptions',
            },
            {
                /*
                 * Where the records disagree with each other. Beside the
                 * other cross-module readers, and open to the same roles
                 * as the directory it reads — a supervisor sees the
                 * findings on their own reports.
                 */
                id: 'analytics-record-checks',
                label: 'Record Checks',
                icon: ShieldCheck,
                href: '/hr/record-checks',
            },
            {
                /*
                 * How the scanner is performing, measured from what HR did
                 * with its proposals. Same roles as the audit log it shares a
                 * gate with — it is a record of what the system and its users
                 * did, not an operational screen.
                 */
                id: 'analytics-scan-accuracy',
                label: 'Scanner Accuracy',
                icon: Gauge,
                href: '/hr/scan-accuracy',
                roles: ['admin', 'hr_staff'],
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
                /*
                 * The seven sections live here, like every other module's
                 * screens.
                 *
                 * They were briefly a single flat link instead, on the
                 * reasoning that SettingsLayout already rendered the same
                 * seven beside the page and a sidebar copy would duplicate
                 * it. Living with it showed the duplication was the cheaper
                 * problem: the in-page list cost the settings forms a column
                 * of width they needed, and at 1024px left them squeezed into
                 * about 468px. The list is gone from the page now, so this is
                 * the only copy again — and the settings pages get the full
                 * width every other screen has.
                 *
                 * Roles mirror SETTINGS_SECTIONS exactly. Appearance and
                 * Security are the two any signed-in person may open; the
                 * rest reconfigure the company rather than the person.
                 */
                children: [
                    {
                        id: 'settings-general',
                        label: 'General',
                        icon: Settings,
                        href: '/settings/general',
                        roles: ['admin'],
                    },
                    {
                        id: 'settings-appearance',
                        label: 'Appearance',
                        icon: Palette,
                        href: '/settings/appearance',
                    },
                    {
                        id: 'settings-notifications',
                        label: 'Notifications',
                        icon: Bell,
                        href: '/settings/notifications',
                        roles: ['admin'],
                    },
                    {
                        id: 'settings-users',
                        label: 'Users & Access',
                        icon: Users,
                        href: '/settings/users',
                        roles: ['admin'],
                    },
                    {
                        id: 'settings-security',
                        label: 'Security',
                        icon: Shield,
                        href: '/settings/security',
                    },
                    {
                        id: 'settings-data',
                        label: 'Data & Backup',
                        icon: Database,
                        href: '/settings/data',
                        roles: ['admin'],
                    },
                    {
                        id: 'settings-integrations',
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

    // For an item whose sub-navigation lives outside the sidebar entirely
    // (Settings: SettingsLayout renders its own section list once you're
    // in) rather than as `children` here — the entry still has to read as
    // current from any page under it, not just the one it happens to link
    // to.
    if (item.activePrefix && pathOf(currentUrl).startsWith(item.activePrefix)) {
        return true;
    }

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
