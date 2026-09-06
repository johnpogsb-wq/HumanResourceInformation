import { Link, usePage } from '@inertiajs/react';
import {
    Bell,
    Database,
    Palette,
    Settings as SettingsIcon,
    Shield,
    Plug,
    Users,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';

/**
 * Settings sections. `admin` marks the ones that reconfigure the company rather
 * than the signed-in person.
 *
 * The single description of what Settings *is*. It was mirrored by the
 * sidebar's `settings` children while Settings lived there, and the two had to
 * be kept agreeing on both the labels and who may open each one; now that
 * Settings is reached from the user card and the topbar instead, this is the
 * only copy — which is one fewer thing that can drift. Still exported, because
 * the server enforces the same `admin` split with a 403 and the two must not
 * disagree about which five those are.
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
 * Shell for every settings page.
 *
 * The sections are back on the page, and **as a row rather than a column**.
 * That distinction is the whole history of this layout: they began as a
 * 224px column beside the content, which at 1024px left the forms about 468px
 * — squeezed at exactly the width where a two-column layout was meant to start
 * helping — so they moved to the sidebar as `children` and the column was
 * deleted. Settings has now left the sidebar too (it is not a sixth module,
 * and it is reached from the user card and the topbar, where an account is
 * reached), which would have left these seven pages with no way to reach each
 * other.
 *
 * A tab row costs height instead of width, and height is the one thing a
 * settings form has to spare. It scrolls sideways rather than wrapping to
 * three ragged lines on a phone.
 */
export default function SettingsLayout({ title, description, children, actions }) {
    const page = usePage();
    const currentPath = page.url.split('?')[0];
    const auth = page.props.auth;

    /*
     * The same role split the server enforces with a 403 — Appearance and
     * Security belong to every signed-in user, the other five reconfigure the
     * company. Drawing a tab that 403s would tell the reader there is
     * something behind it *and* that they are not trusted with it, which is
     * the least useful pair of facts a screen can offer.
     */
    const sections = SETTINGS_SECTIONS.filter(
        (section) => !section.admin || auth?.user?.role === 'admin',
    );

    return (
        <AppLayout
            title="Settings"
            breadcrumbs={[{ label: 'Settings' }, { label: title }]}
            actions={actions}
        >
            {/* Runs to the card's edge on a narrow screen rather than stopping
                inside the page padding, the same treatment the leave calendar's
                scroller uses. */}
            <div className="-mx-4 mb-5 overflow-x-auto px-4 sm:-mx-6 sm:px-6">
                <nav
                    aria-label="Settings sections"
                    className="flex w-max min-w-full gap-1 border-b border-border"
                >
                    {sections.map(({ label, href, icon: Icon }) => {
                        const active = currentPath === href;

                        return (
                            <Link
                                key={href}
                                href={href}
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'flex shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm transition-colors',
                                    active
                                        ? 'border-primary font-medium text-primary'
                                        : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                                )}
                            >
                                <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />
                                {label}
                            </Link>
                        );
                    })}
                </nav>
            </div>

            <div className="mb-5">
                <h2 className="text-lg font-semibold text-foreground">{title}</h2>
                {description && (
                    <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>
                )}
            </div>

            <div className="space-y-5">{children}</div>
        </AppLayout>
    );
}
