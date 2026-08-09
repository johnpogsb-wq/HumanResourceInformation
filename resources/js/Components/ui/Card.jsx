import { cn } from '@/lib/utils';

export function Card({ className, children, ...props }) {
    return (
        <div
            className={cn(
                'rounded-lg border border-border bg-card text-card-foreground shadow-sm',
                className,
            )}
            {...props}
        >
            {children}
        </div>
    );
}

export function CardHeader({ className, title, description, action, children, ...props }) {
    return (
        <div
            className={cn(
                'flex items-start justify-between gap-4 border-b border-border px-5 py-4',
                className,
            )}
            {...props}
        >
            {children ?? (
                <div className="min-w-0">
                    {title && (
                        <h3 className="truncate text-sm font-semibold text-foreground">
                            {title}
                        </h3>
                    )}
                    {description && (
                        <p className="mt-0.5 text-xs text-muted-foreground">{description}</p>
                    )}
                </div>
            )}
            {action && <div className="shrink-0">{action}</div>}
        </div>
    );
}

export function CardBody({ className, children, ...props }) {
    return (
        <div className={cn('px-5 py-4', className)} {...props}>
            {children}
        </div>
    );
}

export function CardFooter({ className, children, ...props }) {
    return (
        <div
            className={cn(
                'flex items-center justify-end gap-2 border-t border-border px-5 py-3',
                className,
            )}
            {...props}
        >
            {children}
        </div>
    );
}

/** Dashboard KPI tile. */
export function StatCard({ label, value, icon: Icon, hint, trend, className }) {
    return (
        <Card className={cn('p-5', className)}>
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                        {label}
                    </p>
                    <p className="mt-2 truncate text-2xl font-semibold tabular-nums text-foreground">
                        {value}
                    </p>
                    {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
                </div>
                {Icon && (
                    <span className="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-primary/10 text-primary">
                        <Icon className="h-5 w-5" aria-hidden="true" />
                    </span>
                )}
            </div>
            {trend && <div className="mt-3 text-xs text-muted-foreground">{trend}</div>}
        </Card>
    );
}
