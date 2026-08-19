import { Link, usePage } from '@inertiajs/react';
import {
    Bell,
    Database,
    Palette,
    Plug,
    Settings as SettingsIcon,
    Shield,
    Users,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';

/**
 * Settings sections. `admin` marks the ones that reconfigure the company rather
 * than the signed-in person.
 */
export const SETTINGS_SECTIONS = [
    { label: 'General', href: '/settings/general', icon: SettingsIcon, admin: true },
    { label: 'Appearance', href: '/settings/appearance', icon: Palette },
    { label: 'Notifications', href: '/settings/notifications', icon: Bell, admin: true },
    { label: 'Users & Access', href: '/settings/users', icon: Users, admin: true },
    { label: 'Security', href: '/settings/security', icon: Shield },
    { label: 'Data & Backup', href: '/settings/data', icon: Database, admin: true },
    { label: 'Integrations', href: '/settings/integrations', icon: Plug, admin: true },
];

/**
 * Shell for every settings page: the section list on the left, the section
 * itself on the right.
 */
export default function SettingsLayout({ title, description, children, actions }) {
    const { url, props } = usePage();
    const path = url.split('?')[0];
    const role = props.auth?.user?.role ?? 'employee';

    const isAdmin = role === 'admin';
    const isHrAdmin = isAdmin || role === 'hr_staff';

    const sections = SETTINGS_SECTIONS.filter((section) => {
        if (section.admin) return isAdmin;
        if (section.hr) return isHrAdmin;

        return true;
    });

    return (
        <AppLayout
            title="Settings"
            breadcrumbs={[{ label: 'Settings' }, { label: title }]}
            actions={actions}
        >
            <div className="flex flex-col gap-6 lg:flex-row">
                {/* Section nav */}
                <nav
                    aria-label="Settings sections"
                    className="scrollbar-thin -mx-1 shrink-0 overflow-x-auto px-1 lg:w-56 lg:overflow-visible"
                >
                    <ul className="flex gap-1 lg:flex-col">
                        {sections.map((section) => {
                            const Icon = section.icon;
                            const active = path === section.href;

                            return (
                                <li key={section.href}>
                                    <Link
                                        href={section.href}
                                        aria-current={active ? 'page' : undefined}
                                        className={cn(
                                            'flex items-center gap-2.5 whitespace-nowrap rounded-lg border px-3 py-2 text-[13px] font-medium',
                                            'transition-colors duration-150',
                                            active
                                                ? 'border-primary/30 bg-primary/10 text-primary'
                                                : 'border-transparent text-muted-foreground hover:bg-secondary/60 hover:text-foreground',
                                        )}
                                    >
                                        <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />
                                        {section.label}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </nav>

                {/* Section content */}
                <div className="min-w-0 flex-1">
                    <div className="mb-5">
                        <h2 className="text-lg font-semibold text-foreground">{title}</h2>
                        {description && (
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                {description}
                            </p>
                        )}
                    </div>

                    <div className="space-y-5">{children}</div>
                </div>
            </div>
        </AppLayout>
    );
}
