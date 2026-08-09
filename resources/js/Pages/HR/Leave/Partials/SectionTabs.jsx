import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

const TABS = [
    { label: 'Requests', href: '/hr/leave' },
    { label: 'Calendar', href: '/hr/leave/calendar' },
    { label: 'Balances', href: '/hr/leave/balances' },
    { label: 'Leave Types', href: '/hr/leave/types' },
];

/** Shared sub-navigation for the four Leave screens. */
export default function SectionTabs() {
    const path = usePage().url.split('?')[0];

    return (
        <div
            className="scrollbar-thin mb-5 flex gap-1 overflow-x-auto border-b border-border"
            role="tablist"
        >
            {TABS.map((tab) => {
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
