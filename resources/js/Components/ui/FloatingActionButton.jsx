import Button from './Button';
import { cn } from '@/lib/utils';

/**
 * A page's one primary "create" action, pinned to the bottom-right corner
 * instead of competing with filters, exports, and other secondary controls
 * for header space.
 *
 * Circular and icon-only below `sm` — a full pill would either overflow a
 * phone-width header or hide its own label — and a labelled pill from `sm`
 * up, the same breakpoint every other Button already reveals its text at.
 *
 * Sits at `bottom-24`, not the `bottom-5` a flush corner button would use:
 * `Toast` already owns that exact corner for flash messages, and the two are
 * often triggered by the same action (open the FAB, save, toast confirms) —
 * flush would put a persistent button and a transient message on top of
 * each other for the toast's few seconds on screen.
 */
export default function FloatingActionButton({ icon: Icon, children, className, ...props }) {
    return (
        <div className="fixed bottom-24 right-5 z-30 sm:right-6">
            <Button
                size="lg"
                aria-label={typeof children === 'string' ? children : undefined}
                className={cn(
                    'h-14 w-14 rounded-full p-0 shadow-lg sm:w-auto sm:gap-2 sm:rounded-full sm:px-5',
                    className,
                )}
                {...props}
            >
                <Icon className="h-6 w-6 shrink-0 sm:h-4 sm:w-4" aria-hidden="true" />
                <span className="hidden sm:inline">{children}</span>
            </Button>
        </div>
    );
}
