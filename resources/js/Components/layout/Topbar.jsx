import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Bell, ChevronRight, Menu, Search, ShieldAlert } from 'lucide-react';
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
    const { auth, pendingApprovals = 0, expiringCredentials = 0 } = usePage().props;
    const user = auth?.user;

    const [query, setQuery] = useState('');

    // The employee directory is the only global search worth having so far.
    const search = (event) => {
        event.preventDefault();

        router.get('/hr/employees', { search: query || undefined });
    };

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

                {/* Quick search */}
                <form onSubmit={search} className="hidden shrink-0 md:block">
                    <label className="sr-only" htmlFor="quick-search">
                        Quick search employees
                    </label>
                    <div className="relative">
                        <Search
                            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <input
                            id="quick-search"
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Quick search…"
                            className={cn(
                                'h-9 w-56 rounded-full border border-input bg-card pl-9 pr-3 text-sm',
                                'text-foreground placeholder:text-muted-foreground/70',
                                'transition-colors focus:border-primary focus:ring-2 focus:ring-ring/30 lg:w-64',
                            )}
                        />
                    </div>
                </form>

                <div className="flex shrink-0 items-center gap-1.5">
                    {actions}

                    <ThemeToggle />

                    {/* A second destination, so it cannot share the bell. Hidden
                        when there is nothing to chase — an always-lit icon stops
                        being read after a week. */}
                    {expiringCredentials > 0 && (
                        <Link
                            href="/hr/credentials"
                            aria-label={`${expiringCredentials} document(s) expired or expiring soon`}
                            title={`${expiringCredentials} expiring or expired document(s)`}
                            className="relative grid h-9 w-9 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                        >
                            <ShieldAlert className="h-4.5 w-4.5" aria-hidden="true" />

                            <span className="absolute -right-0.5 -top-0.5 grid min-w-4 place-items-center rounded-full bg-warning px-1 text-[10px] font-semibold leading-4 text-warning-foreground">
                                {expiringCredentials > 9 ? '9+' : expiringCredentials}
                            </span>
                        </Link>
                    )}

                    <Link
                        href="/hr/leave?status=pending"
                        aria-label={
                            pendingApprovals > 0
                                ? `${pendingApprovals} leave request(s) awaiting your approval`
                                : 'Notifications'
                        }
                        title={
                            pendingApprovals > 0
                                ? `${pendingApprovals} awaiting your approval`
                                : 'No pending approvals'
                        }
                        className="relative grid h-9 w-9 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                    >
                        <Bell className="h-4.5 w-4.5" aria-hidden="true" />

                        {pendingApprovals > 0 && (
                            <span className="absolute -right-0.5 -top-0.5 grid min-w-4 place-items-center rounded-full bg-destructive px-1 text-[10px] font-semibold leading-4 text-destructive-foreground">
                                {pendingApprovals > 9 ? '9+' : pendingApprovals}
                            </span>
                        )}
                    </Link>

                    <div className="ml-1 hidden sm:block">
                        <Dropdown>
                            <Dropdown.Trigger>
                                <button
                                    type="button"
                                    aria-label="Account menu"
                                    className="grid h-9 w-9 place-items-center rounded-full bg-primary text-xs font-semibold text-primary-foreground transition-opacity hover:opacity-90"
                                >
                                    {initials(user?.name)}
                                </button>
                            </Dropdown.Trigger>

                            <Dropdown.Content
                                contentClasses="py-1 bg-popover border border-border rounded-md"
                                align="right"
                            >
                                <div className="border-b border-border px-4 py-2">
                                    <p className="text-xs font-medium text-foreground">
                                        {user?.name}
                                    </p>
                                    <p className="text-[10px] text-muted-foreground">
                                        {ROLE_LABELS[user?.role] ?? 'Employee'}
                                    </p>
                                </div>
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
