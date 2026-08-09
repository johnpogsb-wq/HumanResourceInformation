import { cn } from '@/lib/utils';

const VARIANTS = {
    default: 'bg-secondary text-secondary-foreground border-border',
    primary: 'bg-primary/10 text-primary border-primary/20',
    success: 'bg-success/10 text-success border-success/20',
    warning: 'bg-warning/10 text-warning border-warning/20',
    destructive: 'bg-destructive/10 text-destructive border-destructive/20',
    muted: 'bg-muted text-muted-foreground border-border',
};

/** Employment / request statuses mapped to a consistent colour language. */
export const STATUS_VARIANTS = {
    active: 'success',
    regular: 'success',
    approved: 'success',
    probationary: 'warning',
    pending: 'warning',
    on_leave: 'warning',
    contractual: 'primary',
    'project-based': 'primary',
    resigned: 'muted',
    inactive: 'muted',
    cancelled: 'muted',
    terminated: 'destructive',
    rejected: 'destructive',
};

export default function Badge({ variant = 'default', status, className, children, ...props }) {
    const resolved = status ? (STATUS_VARIANTS[status] ?? 'default') : variant;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium capitalize',
                VARIANTS[resolved],
                className,
            )}
            {...props}
        >
            {children ?? String(status ?? '').replace(/_/g, ' ')}
        </span>
    );
}
