import { Link, usePage } from '@inertiajs/react';
import { isHrefActive } from '@/config/navigation';
import { cn } from '@/lib/utils';

/**
 * Sub-navigation for a module whose screens belong together.
 *
 * A module with seven screens does not want seven sidebar entries — the
 * sidebar is for *where you are in the system*, and these are all one place.
 * The tabs carry the movement between them instead.
 *
 * Roles are filtered here as well as in the sidebar, because the two are read
 * by the same people for different reasons: a tab an employee cannot open is
 * worse than a missing one, since it looks like something being withheld.
 * This is presentation only — every screen behind a tab still authorizes for
 * itself.
 */
export default function SectionTabs({ items = [], className }) {
    const { auth, url } = usePage().props;
    const currentUrl = usePage().url;
    const role = auth?.user?.role;

    const visible = items.filter((item) => !item.roles || item.roles.includes(role));

    // One tab is not a choice. An employee who can only reach Payslips should
    // see the page, not a lone tab above it explaining where they cannot go.
    if (visible.length < 2) return null;

    return (
        <nav
            className={cn('mb-5 flex gap-1 overflow-x-auto border-b border-border', className)}
            aria-label="Section"
        >
            {visible.map((item) => {
                const active = isHrefActive(item.href, url ?? currentUrl);

                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        aria-current={active ? 'page' : undefined}
                        className={cn(
                            'shrink-0 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition-colors',
                            active
                                ? 'border-primary text-primary'
                                : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                        )}
                    >
                        {item.label}
                    </Link>
                );
            })}
        </nav>
    );
}
