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

/**
 * Settings sections. `admin` marks the ones that reconfigure the company rather
 * than the signed-in person.
 *
 * Kept exported because it is the single description of what Settings *is* —
 * the sidebar's `settings` children mirror this list, and the two have to
 * agree on both the labels and who may open each one.
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
 * The section list used to sit here, in a column beside the content. It has
 * moved to the sidebar, where every other module keeps its screens, for a
 * reason that only showed up in use: at 1024px the app sidebar took 260px and
 * the section list another 224px, leaving the forms about 468px — squeezed at
 * exactly the width where a two-column layout was supposed to start helping.
 *
 * With the list gone, a settings page is the same full-width canvas as every
 * other screen, and the navigation lives in the one place a reader already
 * looks for navigation.
 */
export default function SettingsLayout({ title, description, children, actions }) {
    return (
        <AppLayout
            title="Settings"
            breadcrumbs={[{ label: 'Settings' }, { label: title }]}
            actions={actions}
        >
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
