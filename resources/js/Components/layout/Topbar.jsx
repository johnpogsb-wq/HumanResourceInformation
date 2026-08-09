import { Link, usePage } from '@inertiajs/react';
import { Bell, ChevronRight, Menu } from 'lucide-react';
import Dropdown from '@/Components/Dropdown';
import ThemeToggle from '@/Components/layout/ThemeToggle';
import { cn, initials } from '@/lib/utils';

const ROLE_LABELS = {
    admin: 'Administrator',
    hr_staff: 'HR Staff',
    supervisor: 'Supervisor',
    employee: 'Employee',
};

export default function Topbar({ title, breadcrumbs = [], actions, onOpenMobile }) {
    const user = usePage().props.auth?.user;

    return (
        <header className="sticky top-0 z-30 border-b border-border bg-background/85 backdrop-blur">
            <div className="flex h-16 items-center gap-3 px-4 sm:px-6">
                <button
                    type="button"
                    onClick={onOpenMobile}
                    aria-label="Open navigation"
                    className="grid h-9 w-9 shrink-0 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground lg:hidden"
                >
                    <Menu className="h-5 w-5" aria-hidden="true" />
                </button>

                <div className="min-w-0 flex-1">
                    {breadcrumbs.length > 0 && (
                        <nav aria-label="Breadcrumb" className="mb-0.5 hidden sm:block">
                            <ol className="flex items-center gap-1 text-[11px] text-muted-foreground">
                                {breadcrumbs.map((crumb, index) => (
                                    <li
                                        key={`${crumb.label}-${index}`}
                                        className="flex items-center gap-1"
                                    >
                                        {index > 0 && (
                                            <ChevronRight
                                                className="h-3 w-3 opacity-60"
                                                aria-hidden="true"
                                            />
                                        )}
                                        {crumb.href ? (
                                            <Link
                                                href={crumb.href}
                                                className="transition-colors hover:text-foreground"
                                            >
                                                {crumb.label}
                                            </Link>
                                        ) : (
                                            <span className="text-foreground/80">
                                                {crumb.label}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        </nav>
                    )}

                    {title && (
                        <h1 className="truncate text-base font-semibold leading-tight text-foreground">
                            {title}
                        </h1>
                    )}
                </div>

                <div className="flex shrink-0 items-center gap-1.5">
                    {actions}

                    <ThemeToggle />

                    <button
                        type="button"
                        aria-label="Notifications"
                        className="relative grid h-9 w-9 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                    >
                        <Bell className="h-4.5 w-4.5" aria-hidden="true" />
                    </button>

                    <div className="ml-1 hidden sm:block">
                        <Dropdown>
                            <Dropdown.Trigger>
                                <button
                                    type="button"
                                    className={cn(
                                        'flex items-center gap-2 rounded-md py-1 pl-1 pr-2',
                                        'transition-colors hover:bg-secondary',
                                    )}
                                >
                                    <span className="grid h-7 w-7 place-items-center rounded-full bg-primary text-[11px] font-semibold text-primary-foreground">
                                        {initials(user?.name)}
                                    </span>
                                    <span className="hidden text-left md:block">
                                        <span className="block text-xs font-medium leading-tight text-foreground">
                                            {user?.name}
                                        </span>
                                        <span className="block text-[10px] leading-tight text-muted-foreground">
                                            {ROLE_LABELS[user?.role] ?? 'Employee'}
                                        </span>
                                    </span>
                                </button>
                            </Dropdown.Trigger>

                            <Dropdown.Content
                                contentClasses="py-1 bg-popover border border-border rounded-md"
                                align="right"
                            >
                                <Dropdown.Link href="/profile">Profile</Dropdown.Link>
                                <Dropdown.Link href="/logout" method="post" as="button">
                                    Log Out
                                </Dropdown.Link>
                            </Dropdown.Content>
                        </Dropdown>
                    </div>
                </div>
            </div>
        </header>
    );
}
