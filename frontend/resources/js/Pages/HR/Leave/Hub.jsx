import { router } from '@inertiajs/react';
import { useState } from 'react';
import { CalendarDays, ClipboardList, FileText, Wallet2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import ModuleTabs from '@/Components/layout/ModuleTabs';
import RequestsPanel from './Partials/RequestsPanel';
import CalendarPanel from './Partials/CalendarPanel';
import BalancesPanel from './Partials/BalancesPanel';
import TypesPanel from './Partials/TypesPanel';

/**
 * Leave & Absence as one screen, with its four submodules as tabs inside it.
 *
 * They were four separate pages reached from a sidebar dropdown, so moving
 * between them was a navigation: the server rebuilt everything, the scroll
 * position went, and an approver checking the calendar before deciding on a
 * request lost the request they were looking at. **Switching a tab here costs
 * no request at all** — all four sets of props arrive with the page and the
 * active tab is React state.
 *
 * The cost is on the controller rather than here: a visit builds all four
 * tabs even though one is drawn. `LeaveController::index()` says why that
 * trade was taken and what to reach for if a module outgrows it.
 *
 * `tab` is the starting tab, from `?tab=` on a sidebar child or a bookmark,
 * and switching one writes it back with `router.replace` — a client-side
 * visit, so still no request. It has to be in the URL because the sidebar
 * reads the URL to light the right child; kept out of it, every tab lit
 * "Requests". *Replace*, never push: a tab is which part of one screen you are
 * reading, and a history entry per tab would make Back walk through tabs
 * instead of leaving the module.
 */
const TABS = [
    { id: 'requests', label: 'Leave Requests', icon: ClipboardList },
    { id: 'calendar', label: 'Leave Calendar', icon: CalendarDays },
    // "Credits" rather than "Balances" — that is the word on a Philippine
    // leave form and on the payslip line that converts the unused ones.
    { id: 'balances', label: 'Leave Credits', icon: Wallet2 },
    { id: 'types', label: 'Leave Types', icon: FileText },
];

export default function Hub({ tab, requestsTab, calendarTab, balancesTab, typesTab }) {
    const [active, setActive] = useState(tab ?? 'requests');

    const select = (next) => {
        setActive(next);

        const url = new URL(window.location.href);

        if (next === 'requests') {
            url.searchParams.delete('tab');
        } else {
            url.searchParams.set('tab', next);
        }

        router.replace({
            url: `${url.pathname}${url.search}`,
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <AppLayout
            title="Leave & Absence"
            breadcrumbs={[{ label: 'Human Resource' }, { label: 'Leave & Absence' }]}
        >
            <ModuleTabs
                tabs={TABS}
                active={active}
                onSelect={select}
                counts={counts(requestsTab, typesTab)}
            />

            {active === 'requests' && <RequestsPanel {...requestsTab} />}
            {active === 'calendar' && <CalendarPanel {...calendarTab} />}
            {active === 'balances' && <BalancesPanel {...balancesTab} />}
            {active === 'types' && <TypesPanel {...typesTab} />}
        </AppLayout>
    );
}

/**
 * The figures that ride on the tab row.
 *
 * Only where the number is something to act on: requests awaiting a decision,
 * and how many leave types are configured. A count on every tab would be four
 * numbers competing for the same glance, and a badge that is always lit is one
 * nobody reads — the rule the credential indicator already follows.
 */
function counts(requestsTab, typesTab) {
    const awaiting = requestsTab?.summary?.pending ?? 0;

    return {
        requests: awaiting > 0 ? awaiting : null,
        types: typesTab?.types?.length ?? null,
    };
}
