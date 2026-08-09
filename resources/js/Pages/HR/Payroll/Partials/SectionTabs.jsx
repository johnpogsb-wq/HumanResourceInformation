import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

const HR_TABS = [
    { label: 'Payroll Runs', href: '/hr/payroll' },
    { label: 'Payslips', href: '/hr/payroll/payslips' },
    { label: 'Allowances & Loans', href: '/hr/payroll/compensation' },
];

/** Employees only ever see their own payslips, so they get no tab bar. */
export default function SectionTabs({ show = true }) {
    const path = usePage().url.split('?')[0];

    if (!show) return null;

    return (
        <div
            className="scrollbar-thin mb-5 flex gap-1 overflow-x-auto border-b border-border"
            role="tablist"
        >
            {HR_TABS.map((tab) => {
                const active = path === tab.href;

                return (
                    <Link
                        key={tab.href}
                        href={tab.href}
                        role="tab"
                        aria-selected={active}
                        className={cn(
                            '-mb-px whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-medium transition-colors',
                            active
                                ? 'border-primary text-primary'
                                : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                        )}
                    >
                        {tab.label}
                    </Link>
                );
            })}
        </div>
    );
}
