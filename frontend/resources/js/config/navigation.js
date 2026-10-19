import {
    BadgeCheck,
    CalendarClock,
    CalendarDays,
    CalendarRange,
    ClipboardList,
    Clock,
    DoorOpen,
    FileText,
    HandCoins,
    IdCard,
    Inbox,
    LayoutDashboard,
    MonitorSmartphone,
    Network,
    Receipt,
    ScanFace,
    ScrollText,
    Settings,
    Target,
    Timer,
    TrendingUp,
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
                    /*
                     * Departments stays; Positions, Clients, Checks &
                     * Readiness and the Archive were removed from the sidebar
                     * on the owner's instruction.
                     *
                     * **The routes, controllers and policies are untouched** —
                     * this is a change to what the sidebar offers, not to what
                     * the system does. Every one of those screens still works
                     * and still answers its URL; what they no longer have is a
                     * row pointing at them.
                     *
                     * The cost is the one `Organization Chart` already had:
                     * Positions had no link anywhere then, and the only way in
                     * was to open Departments and find the way across. It is
                     * the same now for all four, and it is deliberate rather
                     * than overlooked — recorded here because the next reader
                     * will otherwise take a missing row for a missing feature.
                     */
                    {
                        id: 'employee-departments',
                        label: 'Departments',
                        icon: Network,
                        href: '/hr/departments',
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
                label: 'Timekeeping',
                icon: Clock,
                children: [
                    {
                        // Every employee's own Time in / Time out, verified
                        // by their face on the device camera.
                        id: 'timekeeping-clock',
                        label: 'Time Clock',
                        icon: Timer,
                        href: '/hr/timekeeping/clock',
                    },
                    {
                        id: 'timekeeping-records',
                        label: 'Daily Time Records',
                        icon: ClipboardList,
                        href: '/hr/timekeeping',
                    },
                    {
                        id: 'timekeeping-calendar',
                        label: 'Attendance Calendar',
                        icon: CalendarClock,
                        href: '/hr/timekeeping/calendar',
                    },
                    {
                        id: 'timekeeping-shifts',
                        label: 'Shifts & Rest Days',
                        icon: CalendarRange,
                        href: '/hr/timekeeping/shifts',
                        roles: ['admin', 'hr_staff'],
                    },
                    {
                        // Every role: HR enrolls anybody, everyone else their own face once.
                        id: 'timekeeping-faces',
                        label: 'Face Enrollment',
                        icon: ScanFace,
                        href: '/hr/timekeeping/faces',
                    },
                    // Overtime Requests, Time Corrections, Cutoff Closing,
                    // Client Timesheets and the Holiday Calendar were removed
                    // from the sidebar on the owner's instruction. The screens
                    // and their routes are untouched — see the note in
                    // Employee Information above. The holidays still drive
                    // holiday pay, leave and the DTR; /hr/timekeeping/holidays
                    // is where HR adds the movable ones each year.
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
                    // Salaries & Adjustments and Compliance were removed from
                    // the sidebar on the owner's instruction. The screens and
                    // their routes are untouched — see the note in Employee
                    // Information above.
                    {
                        id: 'payroll-compensation',
                        label: 'Allowances & Loans',
                        icon: HandCoins,
                        href: '/hr/payroll/compensation',
                        roles: ['admin', 'hr_staff'],
                    },
                    // Separation & Final Pay, back in the sidebar under the
                    // name the owner uses for it. "Back pay" is what
                    // Philippine staff call final pay.
                    {
                        id: 'payroll-back-pay',
                        label: 'Back Pay',
                        icon: DoorOpen,
                        href: '/hr/payroll/separations',
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
     * The AI & Analytics group is gone, removed from the sidebar on the
     * owner's instruction — Reports, Workforce Analytics, the AI Batch
     * Scanner and Scanner Accuracy with it.
     *
     * Its routes, controllers and policies are untouched: /hr/reports,
     * /hr/analytics/workforce, /hr/employees/documents/batch and
     * /hr/scan-accuracy all still answer. What went is the group heading and
     * the four rows under it.
     */
    /*
     * Configuring the app, kept apart from doing the company's work.
     *
     * Settings has been in three places now, and the group label is what makes
     * this one different from the second. It was a 224px column beside the
     * page (deleted — it cost width the forms had none of), then a sidebar
     * entry with seven `children` (deleted — a dropdown of seven settings
     * screens sits level with Payroll's seven, which files "change my
     * password" as a peer of a payroll run), then two gears and no sidebar
     * entry at all.
     *
     * This is one entry under a label that says what it is. The objection to
     * the second answer was never the sidebar, it was the ranking — and
     * "ADMINISTRATION" is the thing that ranks it. The seven sections stay
     * where they already live: `SettingsLayout` renders them as a row on the
     * page, so the sidebar carries one line rather than seven.
     *
     * No `roles`. `/settings` redirects to the first section the person may
     * open, and Appearance and Security belong to every signed-in user — a
     * role filter here would hide the door to somebody's own password.
     *
     * `activePrefix` is what lights it from `/settings/security` and the rest:
     * the sub-navigation is not `children`, so there is no child href for
     * `bestMatch()` to find.
     */
    {
        label: 'Administration',
        items: [
            {
                /*
                 * Was reachable only as a Settings section, which filed "who
                 * may open a 201 file" behind the same door as the company's
                 * date format. It is the access-control screen *and* the
                 * access review, so it belongs under the heading that says
                 * administration. It stays a Settings section too — the same
                 * two-doors arrangement the topbar gear and this group
                 * already have for Settings itself.
                 */
                id: 'users',
                label: 'Users & Access',
                icon: Users,
                href: '/settings/users',
                roles: ['admin'],
            },
            {
                /*
                 * The audit trail, out of the bottom of Settings → Security
                 * and onto its own screen. Carries two roles where Users &
                 * Access carries one: `viewAuditLog` is `isHrAdmin()`, and
                 * reading the log is not the same act as handing out access.
                 */
                id: 'audit-logs',
                label: 'Audit Logs',
                icon: ScrollText,
                href: '/settings/audit-logs',
                roles: ['admin', 'hr_staff'],
            },
            // `Archive Module` was here, pointing at the same `/hr/archive`
            // the Employee Information dropdown also offered. Both rows were
            // removed on the owner's instruction; the screen still answers its
            // URL.
            {
                /*
                 * Who is signed in right now, and how to sign them out.
                 *
                 * `roles: ['super_admin']` is the narrowest entry in this
                 * file, and the only one an Administrator does not see: the
                 * list is every signed-in person's address and device, which
                 * is a map of the workforce's whereabouts nothing else here
                 * hands over — and ending somebody's session is an incident
                 * response rather than an HR task. `visibleGroups()` lets a
                 * super administrator through everything, and for an admin
                 * neither the `admin` shortcut nor their own role matches, so
                 * it drops out for them.
                 */
                id: 'sessions',
                label: 'Active Sessions',
                icon: MonitorSmartphone,
                href: '/settings/sessions',
                roles: ['super_admin'],
            },
            {
                id: 'system-settings',
                label: 'System Settings',
                icon: Settings,
                href: '/settings',
                activePrefix: '/settings',
            },
        ],
    },
];

/*
 * Settings is deliberately not in this list.
 *
 * It sat here as a "System" group of seven children, alongside the five
 * modules — which put "change my password" and "back up the database" at the
 * same level as Payroll. Settings is not a sixth module: it configures the
 * app and the account rather than doing the company's work, and it is reached
 * from where an account is reached, which is the user card at the foot of this
 * sidebar and the top right of the topbar.
 *
 * Nothing about the routes or permissions moved with it, and `ALL_HREFS` is
 * derived from the groups above — so `bestMatch()` simply finds nothing on a
 * settings page, which is correct: there is no sidebar entry for it to light.
 *
 * `SettingsLayout` carries the seven sections now, as a row of tabs across the
 * top. That is not the old left-hand column coming back: the column cost the
 * forms 224px of width at exactly the size where they needed it, and a tab row
 * costs height, which these pages have.
 */

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
        ...(item.activePrefixes ?? []),
    ]),
).sort((a, b) => b.length - a.length);

/** The single nav href that best describes the current URL. */
export function bestMatch(currentUrl) {
    const path = pathOf(currentUrl);

    /*
     * A tabbed module screen (Leave) is one URL with `?tab=`, while its
     * sidebar children keep their own paths and redirect onto the tab — so
     * `/hr/leave?tab=calendar` is read as `/hr/leave/calendar`. Without this
     * every tab lit the first child, because the path never changes.
     */
    const tab = new URLSearchParams(currentUrl.split('?')[1]?.split('#')[0] ?? '').get('tab');

    if (tab && ALL_HREFS.includes(`${path}/${tab}`)) {
        return `${path}/${tab}`;
    }

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
    /*
     * ...but only while no other entry owns the URL outright. `/settings` is
     * System Settings' prefix and `/settings/users` is Users & Access's own
     * href, so without this both rows light up on that page — and two current
     * rows tell the reader neither.
     */
    if (item.activePrefix && pathOf(currentUrl).startsWith(item.activePrefix)) {
        const owner = bestMatch(currentUrl);

        return owner === null || owner === item.href;
    }

    if (
        item.activePrefixes &&
        item.activePrefixes.some((prefix) => pathOf(currentUrl).startsWith(prefix))
    ) {
        const owner = bestMatch(currentUrl);

        return owner === null || owner === item.href || item.activePrefixes.includes(owner);
    }

    return (item.children ?? []).some((child) => isHrefActive(child.href, currentUrl));
}

/**
 * A `divider` belongs to the run of children after it, not to the one child
 * that happens to carry it: when role filtering removes that child, the heading
 * moves to the next survivor rather than disappearing with it. A set of screens
 * that silently loses its label for one role is the bug this guards against.
 */
export function visibleGroups(groups, role) {
    const isSuperAdmin = role === 'super_admin';
    const isAdmin = isSuperAdmin || role === 'admin';

    const allowed = (entry) => {
        if (!entry.roles) return true;
        if (isSuperAdmin) return true;
        if (entry.roles.includes('admin') && isAdmin) return true;
        return entry.roles.includes(role);
    };

    return groups
        .map((group) => ({
            ...group,
            items: group.items
                .filter(allowed)
                .map((item) => {
                    if (!item.children) return item;

                    // The dropped children are read before they go, so a
                    // heading on one of them survives onto the next.
                    let pending = null;
                    const kept = [];

                    for (const child of item.children) {
                        if (!allowed(child)) {
                            pending = pending ?? child.divider ?? null;
                            continue;
                        }

                        kept.push(
                            pending && !child.divider ? { ...child, divider: pending } : child,
                        );
                        pending = null;
                    }

                    return { ...item, children: kept };
                })
                // A parent whose children are all hidden has nothing to show.
                .filter((item) => item.href || (item.children?.length ?? 0) > 0),
        }))
        .filter((group) => group.items.length > 0);
}
