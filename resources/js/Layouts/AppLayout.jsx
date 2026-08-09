import { Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Sidebar from '@/Components/layout/Sidebar';
import Topbar from '@/Components/layout/Topbar';
import Toast from '@/Components/ui/Toast';
import { cn } from '@/lib/utils';

const COLLAPSE_KEY = 'primepower-sidebar-collapsed';

/**
 * Shell for every authenticated HRIS page: sidebar + topbar + content well.
 */
export default function AppLayout({ title, breadcrumbs, actions, children }) {
    const { flash } = usePage().props;

    const [collapsed, setCollapsed] = useState(false);
    const [mobileOpen, setMobileOpen] = useState(false);

    // Restore the rail state after hydration so SSR markup stays stable.
    useEffect(() => {
        setCollapsed(window.localStorage.getItem(COLLAPSE_KEY) === '1');
    }, []);

    const toggleCollapsed = () => {
        setCollapsed((current) => {
            const next = !current;
            window.localStorage.setItem(COLLAPSE_KEY, next ? '1' : '0');
            return next;
        });
    };

    return (
        <div className="min-h-screen bg-background">
            {title && <Head title={title} />}

            <Sidebar
                collapsed={collapsed}
                onToggleCollapsed={toggleCollapsed}
                mobileOpen={mobileOpen}
                onCloseMobile={() => setMobileOpen(false)}
            />

            <div
                className={cn(
                    'flex min-h-screen flex-col transition-all duration-300',
                    collapsed ? 'lg:pl-sidebar-collapsed' : 'lg:pl-sidebar',
                )}
            >
                <Topbar
                    title={title}
                    breadcrumbs={breadcrumbs}
                    actions={actions}
                    onOpenMobile={() => setMobileOpen(true)}
                />

                <main className="flex-1 px-4 py-6 sm:px-6">
                    <div className="mx-auto w-full max-w-7xl">{children}</div>
                </main>
            </div>

            <Toast flash={flash} />
        </div>
    );
}
