import {
    CalendarDays,
    Clock,
    IdCard,
    LayoutDashboard,
    TrendingUp,
    Users,
    Wallet,
} from 'lucide-react';

/**
 * Sidebar navigation for Core Transaction 2 — HRIS.
 * Each group may carry a label; each item may carry `children` (accordion),
 * a `badge`, and `roles` (omit = visible to every authenticated role).
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
                id: 'hr',
                label: 'HRIS',
                icon: Users,
                children: [
                    {
                        id: 'employee-info',
                        label: 'Employee Information',
                        icon: IdCard,
                        href: '/hr/employees',
                    },
                    {
                        id: 'timekeeping',
                        label: 'Timekeeping & Attendance',
                        icon: Clock,
                        href: '/hr/timekeeping',
                    },
                    {
                        id: 'leave',
                        label: 'Leave & Absence',
                        icon: CalendarDays,
                        href: '/hr/leave',
                    },
                    {
                        id: 'payroll',
                        label: 'Payroll & Compensation',
                        icon: Wallet,
                        // Employees land on their own payslips; the controller
                        // redirects anyone who cannot run payroll.
                        href: '/hr/payroll',
                    },
                    {
                        id: 'performance',
                        label: 'Performance Management',
                        icon: TrendingUp,
                        href: '/hr/performance',
                    },
                ],
            },
        ],
    },
];

/** Strip query/hash, then match the nav href as a path prefix. */
export function isHrefActive(href, currentUrl) {
    if (!href) return false;

    const path = currentUrl.split('?')[0].split('#')[0];

    return path === href || path.startsWith(`${href}/`);
}

export function visibleGroups(groups, role) {
    const allowed = (entry) => !entry.roles || entry.roles.includes(role);

    return groups
        .map((group) => ({
            ...group,
            items: group.items.filter(allowed).map((item) => ({
                ...item,
                children: item.children?.filter(allowed),
            })),
        }))
        .filter((group) => group.items.length > 0);
}
