import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { ChevronDown, LogOut, PanelLeftClose, PanelLeftOpen } from 'lucide-react';
import PrimePowerLogo from '@/Components/layout/PrimePowerLogo';
import { NAV_GROUPS, isHrefActive, isItemActive, visibleGroups } from '@/config/navigation';
import { cn, initials } from '@/lib/utils';

export default function Sidebar({ collapsed, onToggleCollapsed, mobileOpen, onCloseMobile }) {
    const { props, url: currentUrl } = usePage();
    const user = props.auth?.user;
    const role = user?.role ?? 'employee';

    const groups = useMemo(() => visibleGroups(NAV_GROUPS, role), [role]);

    // Accordion: at most one module open at a time.
    const [expandedModule, setExpandedModule] = useState(null);

    // Keep the accordion in sync with whichever module owns the current URL.
    useEffect(() => {
        const owner = groups
            .flatMap((group) => group.items)
            .find((item) =>
                item.children?.some((child) => isHrefActive(child.href, currentUrl)),
            );

        if (owner) setExpandedModule(owner.id);
    }, [currentUrl, groups]);

    const handleParentClick = (item) => {
        const alreadyOpen = expandedModule === item.id;

        setExpandedModule(alreadyOpen ? null : item.id);

        // Opening a module lands the user on its first page.
        if (!alreadyOpen) {
            const first = item.children?.[0];

            if (first?.href && !isHrefActive(first.href, currentUrl)) {
                router.visit(first.href);
            }
        }
    };

    return (
        <>
            {/* Mobile scrim */}
            <div
                className={cn(
                    'fixed inset-0 z-40 bg-foreground/40 backdrop-blur-sm transition-opacity lg:hidden',
                    mobileOpen ? 'opacity-100' : 'pointer-events-none opacity-0',
                )}
                onClick={onCloseMobile}
                aria-hidden="true"
            />

            <aside
                className={cn(
                    'fixed inset-y-0 left-0 z-50 flex flex-col border-r border-sidebar-border bg-sidebar',
                    'transition-all duration-300 lg:translate-x-0',
                    collapsed ? 'w-sidebar-collapsed' : 'w-sidebar',
                    mobileOpen ? 'translate-x-0' : '-translate-x-full',
                )}
            >
                {/* Logo */}
                <div
                    className={cn(
                        'flex h-16 shrink-0 items-center border-b border-sidebar-border',
                        collapsed ? 'justify-center px-2' : 'px-4',
                    )}
                >
                    <Link href="/dashboard" className="min-w-0">
                        <PrimePowerLogo collapsed={collapsed} />
                    </Link>
                </div>

                {/* Nav */}
                <nav className="scrollbar-thin flex-1 space-y-5 overflow-y-auto overflow-x-hidden px-3 py-4">
                    {groups.map((group, groupIndex) => (
                        <div key={group.label ?? `group-${groupIndex}`}>
                            {group.label && !collapsed && (
                                <p className="mb-1.5 px-2 text-[10px] font-semibold uppercase tracking-[0.08em] text-sidebar-muted">
                                    {group.label}
                                </p>
                            )}
                            {group.label && collapsed && (
                                <div
                                    className="mx-2 mb-2 h-px bg-sidebar-border"
                                    aria-hidden="true"
                                />
                            )}

                            <ul className="space-y-0.5">
                                {group.items.map((item) => {
                                    const Icon = item.icon;
                                    const hasChildren = Boolean(item.children?.length);
                                    const active = isItemActive(item, currentUrl);
                                    const isOpen = expandedModule === item.id && !collapsed;

                                    const rowClasses = cn(
                                        'group flex w-full items-center gap-2.5 rounded-lg py-2 text-[13px] font-medium',
                                        'transition-colors duration-150',
                                        collapsed ? 'justify-center px-0' : 'px-2.5',
                                        active
                                            ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                                            : 'text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground',
                                    );

                                    return (
                                        <li key={item.id}>
                                            {hasChildren ? (
                                                <button
                                                    type="button"
                                                    onClick={() => handleParentClick(item)}
                                                    className={rowClasses}
                                                    title={collapsed ? item.label : undefined}
                                                    aria-expanded={isOpen}
                                                >
                                                    <Icon
                                                        className={cn(
                                                            'h-4.5 w-4.5 shrink-0',
                                                            active && 'text-sidebar-primary',
                                                        )}
                                                        aria-hidden="true"
                                                    />
                                                    {!collapsed && (
                                                        <>
                                                            <span className="flex-1 truncate text-left leading-tight">
                                                                {item.label}
                                                            </span>
                                                            <ChevronDown
                                                                className={cn(
                                                                    'h-4 w-4 shrink-0 text-sidebar-muted transition-transform duration-200',
                                                                    isOpen && 'rotate-180',
                                                                )}
                                                                aria-hidden="true"
                                                            />
                                                        </>
                                                    )}
                                                </button>
                                            ) : (
                                                <Link
                                                    href={item.href}
                                                    onClick={onCloseMobile}
                                                    className={rowClasses}
                                                    title={collapsed ? item.label : undefined}
                                                    aria-current={active ? 'page' : undefined}
                                                >
                                                    <Icon
                                                        className={cn(
                                                            'h-4.5 w-4.5 shrink-0',
                                                            active && 'text-sidebar-primary',
                                                        )}
                                                        aria-hidden="true"
                                                    />
                                                    {!collapsed && (
                                                        <span className="flex-1 truncate">
                                                            {item.label}
                                                        </span>
                                                    )}
                                                </Link>
                                            )}

                                            {/* Dropdown of sub-pages */}
                                            {hasChildren && (
                                                <div
                                                    className={cn(
                                                        'grid transition-all duration-300',
                                                        isOpen
                                                            ? 'grid-rows-[1fr] opacity-100'
                                                            : 'grid-rows-[0fr] opacity-0',
                                                    )}
                                                >
                                                    <ul className="ml-[1.4rem] mt-0.5 space-y-0.5 overflow-hidden border-l border-sidebar-border pl-2.5">
                                                        {item.children.map((child) => {
                                                            const ChildIcon = child.icon;
                                                            const childActive = isHrefActive(
                                                                child.href,
                                                                currentUrl,
                                                            );

                                                            return (
                                                                <li key={child.id}>
                                                                    <Link
                                                                        href={child.href}
                                                                        onClick={onCloseMobile}
                                                                        tabIndex={
                                                                            isOpen ? 0 : -1
                                                                        }
                                                                        aria-current={
                                                                            childActive
                                                                                ? 'page'
                                                                                : undefined
                                                                        }
                                                                        className={cn(
                                                                            'flex items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-[12.5px]',
                                                                            'transition-colors duration-150',
                                                                            childActive
                                                                                ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                                                                                : 'text-sidebar-foreground/60 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground',
                                                                        )}
                                                                    >
                                                                        <ChildIcon
                                                                            className="h-4 w-4 shrink-0"
                                                                            aria-hidden="true"
                                                                        />
                                                                        <span className="flex-1 truncate">
                                                                            {child.label}
                                                                        </span>
                                                                        {child.badge && (
                                                                            <span className="rounded-full bg-sidebar-primary/15 px-1.5 py-0.5 text-[10px] font-semibold text-sidebar-primary">
                                                                                {child.badge}
                                                                            </span>
                                                                        )}
                                                                    </Link>
                                                                </li>
                                                            );
                                                        })}
                                                    </ul>
                                                </div>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    ))}
                </nav>

                {/* Collapse toggle — desktop only */}
                <div className="hidden shrink-0 px-3 pb-2 lg:block">
                    <button
                        type="button"
                        onClick={onToggleCollapsed}
                        title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                        className={cn(
                            'flex w-full items-center gap-2.5 rounded-lg py-2 text-[13px] font-medium text-sidebar-muted',
                            'transition-colors hover:bg-sidebar-accent/50 hover:text-sidebar-foreground',
                            collapsed ? 'justify-center px-0' : 'px-2.5',
                        )}
                    >
                        {collapsed ? (
                            <PanelLeftOpen className="h-4.5 w-4.5" aria-hidden="true" />
                        ) : (
                            <>
                                <PanelLeftClose className="h-4.5 w-4.5" aria-hidden="true" />
                                <span>Collapse</span>
                            </>
                        )}
                    </button>
                </div>

                {/* User card */}
                <div className="shrink-0 border-t border-sidebar-border p-3">
                    <div
                        className={cn(
                            'flex items-center gap-2.5',
                            collapsed && 'justify-center',
                        )}
                    >
                        <span
                            className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-sidebar-primary text-xs font-semibold text-sidebar-primary-foreground"
                            title={collapsed ? user?.name : undefined}
                        >
                            {initials(user?.name)}
                        </span>

                        {!collapsed && (
                            <>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-[12.5px] font-semibold uppercase tracking-wide text-sidebar-foreground">
                                        {user?.name}
                                    </p>
                                    <p className="truncate text-[11px] text-sidebar-muted">
                                        {user?.email}
                                    </p>
                                </div>

                                <Link
                                    href="/logout"
                                    method="post"
                                    as="button"
                                    aria-label="Log out"
                                    title="Log out"
                                    className="shrink-0 rounded-md p-1.5 text-sidebar-muted transition-colors hover:bg-destructive/10 hover:text-destructive"
                                >
                                    <LogOut className="h-4 w-4" aria-hidden="true" />
                                </Link>
                            </>
                        )}
                    </div>
                </div>
            </aside>
        </>
    );
}
