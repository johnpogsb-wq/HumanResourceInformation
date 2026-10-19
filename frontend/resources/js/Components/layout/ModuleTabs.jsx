import { cn } from '@/lib/utils';

/**
 * The tab row inside a merged module screen.
 *
 * **Buttons, not links, and that is the whole point of it.** A module's
 * submodules used to be separate pages, so moving between them was a
 * navigation — the server rebuilt everything and the reader lost their place.
 * These switch React state instead: **no request, no re-render of the page
 * shell, no lost scroll position.** A `<Link>` here would quietly put the
 * round trip back.
 *
 * The cost of that is paid in the controller, which builds every tab's props
 * on the one visit. Each module's `index()` says why, and what to reach for
 * if the module ever outgrows it.
 *
 * `counts` is optional and sparse on purpose: a figure rides a tab only where
 * it is something to act on. Four numbers competing for one glance is four
 * numbers nobody reads, which is the rule the topbar's credential indicator
 * already follows in hiding itself at zero.
 */
export default function ModuleTabs({ tabs, active, onSelect, counts = {} }) {
    if (!Array.isArray(tabs) || tabs.length < 2) return null;

    return (
        <div className="mb-5 border-b border-border">
            {/*
                Scrolls rather than wraps below `sm`. Payroll has seven tabs and
                a phone fits about two; wrapped, the row becomes four stacked
                lines that push the screen's own content off the top — the thing
                the reader came for. The negative margin lets it run to the
                page's edge instead of stopping inside the padding, the same way
                the leave calendar's own scroller does.
            */}
            <div
                role="tablist"
                className="-mx-4 flex gap-1 overflow-x-auto px-4 pb-px sm:mx-0 sm:flex-wrap sm:px-0"
            >
                {tabs.map((tab) => {
                    const Icon = tab.icon;
                    const isActive = active === tab.id;
                    const count = counts[tab.id];

                    return (
                        <button
                            key={tab.id}
                            type="button"
                            role="tab"
                            aria-selected={isActive}
                            onClick={() => onSelect(tab.id)}
                            className={cn(
                                'flex shrink-0 items-center gap-2 rounded-t-lg border-b-2 px-3.5 py-2.5 text-sm font-medium transition-colors',
                                isActive
                                    ? 'border-primary bg-primary/5 text-primary'
                                    : 'border-transparent text-muted-foreground hover:border-border hover:bg-muted/40 hover:text-foreground',
                            )}
                        >
                            {Icon && (
                                <Icon
                                    className={cn(
                                        'h-4 w-4 shrink-0',
                                        isActive ? 'text-primary' : 'text-muted-foreground',
                                    )}
                                    aria-hidden="true"
                                />
                            )}
                            <span className="whitespace-nowrap">{tab.label}</span>

                            {/* Null rather than 0, so an empty queue is quiet. */}
                            {count !== null && count !== undefined && (
                                <span
                                    className={cn(
                                        'rounded-full px-2 py-0.5 text-[10px] font-semibold tabular-nums',
                                        isActive
                                            ? 'bg-primary/15 text-primary'
                                            : 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    {count}
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
