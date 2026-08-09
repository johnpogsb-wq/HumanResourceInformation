import { useEffect, useState } from 'react';
import { AlertCircle, CheckCircle2, X } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Surfaces Laravel session flash messages (`success` / `error`) shared through
 * HandleInertiaRequests.
 */
export default function Toast({ flash, duration = 4500 }) {
    const message = flash?.success ?? flash?.error ?? null;
    const isError = Boolean(flash?.error);

    const [visible, setVisible] = useState(false);

    useEffect(() => {
        if (!message) {
            setVisible(false);
            return;
        }

        setVisible(true);
        const timer = setTimeout(() => setVisible(false), duration);

        return () => clearTimeout(timer);
    }, [message, duration]);

    if (!message || !visible) return null;

    const Icon = isError ? AlertCircle : CheckCircle2;

    return (
        <div
            role="status"
            aria-live="polite"
            className="fixed bottom-5 right-5 z-[60] animate-slide-up"
        >
            <div
                className={cn(
                    'flex max-w-sm items-start gap-2.5 rounded-lg border bg-popover px-4 py-3 shadow-lg',
                    isError ? 'border-destructive/30' : 'border-success/30',
                )}
            >
                <Icon
                    className={cn(
                        'mt-0.5 h-4.5 w-4.5 shrink-0',
                        isError ? 'text-destructive' : 'text-success',
                    )}
                    aria-hidden="true"
                />
                <p className="flex-1 text-sm text-foreground">{message}</p>
                <button
                    type="button"
                    onClick={() => setVisible(false)}
                    aria-label="Dismiss"
                    className="-mr-1 shrink-0 rounded p-0.5 text-muted-foreground transition-colors hover:text-foreground"
                >
                    <X className="h-3.5 w-3.5" aria-hidden="true" />
                </button>
            </div>
        </div>
    );
}
