import { Menu, MenuButton, MenuItem, MenuItems, Transition } from '@headlessui/react';
import { Link, usePage } from '@inertiajs/react';
import { Fragment } from 'react';
import { Check, ChevronDown } from 'lucide-react';
import { isHrefActive } from '@/config/navigation';
import { cn } from '@/lib/utils';

/**
 * Sub-navigation for a module whose screens belong together.
 *
 * A module with seven screens does not want seven sidebar entries — the
 * sidebar is for *where you are in the system*, and these are all one place.
 * A dropdown carries the movement between them instead: the button names
 * *where you are*, and opening it shows *where else you can go* — closer to
 * how the sidebar itself reads than a row of tabs competing for width.
 *
 * Roles are filtered here as well as in the sidebar, because the two are read
 * by the same people for different reasons: an entry an employee cannot open
 * is worse than a missing one, since it looks like something being withheld.
 * This is presentation only — every screen behind an entry still authorizes
 * for itself.
 */
export default function SectionTabs({ items = [], className }) {
    const { auth, url } = usePage().props;
    const currentUrl = usePage().url;
    const role = auth?.user?.role;

    const visible = items.filter((item) => !item.roles || item.roles.includes(role));

    // One entry is not a choice. An employee who can only reach Payslips
    // should see the page, not a dropdown above it explaining where they
    // cannot go.
    if (visible.length < 2) return null;

    const current = visible.find((item) => isHrefActive(item.href, url ?? currentUrl));

    return (
        <Menu as="div" className={cn('relative mb-5 inline-block', className)}>
            <MenuButton
                className="flex items-center gap-1.5 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground shadow-sm transition-colors hover:bg-secondary/60 focus:outline-none focus:ring-2 focus:ring-ring/30"
                aria-label="Section"
            >
                {current?.label ?? 'Section'}
                <ChevronDown className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
            </MenuButton>

            <Transition
                as={Fragment}
                enter="transition ease-out duration-150"
                enterFrom="opacity-0 translate-y-1"
                enterTo="opacity-100 translate-y-0"
                leave="transition ease-in duration-100"
                leaveFrom="opacity-100 translate-y-0"
                leaveTo="opacity-0 translate-y-1"
            >
                <MenuItems className="absolute left-0 z-20 mt-1.5 w-56 origin-top-left rounded-md border border-border bg-popover p-1 text-popover-foreground shadow-lg focus:outline-none">
                    {visible.map((item) => {
                        const active = isHrefActive(item.href, url ?? currentUrl);

                        return (
                            <MenuItem key={item.href}>
                                {({ focus }) => (
                                    <Link
                                        href={item.href}
                                        aria-current={active ? 'page' : undefined}
                                        className={cn(
                                            'flex items-center gap-2 rounded-sm px-2.5 py-2 text-sm transition-colors',
                                            active
                                                ? 'font-medium text-primary'
                                                : 'text-foreground',
                                            focus && 'bg-secondary/60',
                                        )}
                                    >
                                        <Check
                                            className={cn(
                                                'h-3.5 w-3.5 shrink-0',
                                                active ? 'opacity-100' : 'opacity-0',
                                            )}
                                            aria-hidden="true"
                                        />
                                        {item.label}
                                    </Link>
                                )}
                            </MenuItem>
                        );
                    })}
                </MenuItems>
            </Transition>
        </Menu>
    );
}
