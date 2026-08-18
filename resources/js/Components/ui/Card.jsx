import { ArrowDown, ArrowUp } from 'lucide-react';
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

/**
 * Tones a tile may carry.
 *
 * Colour here encodes the figure's *valence* — it is not decoration. A number
 * that is neither good nor bad keeps the default, because once every tile is
 * coloured none of them reads as a signal any more. The `grade-*` entries are
 * stops on the shared good -> bad ramp and are for figures that sit on a
 * scale; the named tones are for figures that mean one thing.
 *
 * Spelled out rather than built from a template string: Tailwind scans for
 * literal class names.
 */
const ICON_TONES = {
    primary: 'bg-primary/10 text-primary',
    info: 'bg-info/10 text-info',
    success: 'bg-success/10 text-success',
    warning: 'bg-warning/10 text-warning',
    destructive: 'bg-destructive/10 text-destructive',
    muted: 'bg-muted text-muted-foreground',
};

const TEXT_TONES = {
    default: 'text-foreground',
    primary: 'text-primary',
    info: 'text-info',
    success: 'text-success',
    warning: 'text-warning',
    destructive: 'text-destructive',
    muted: 'text-muted-foreground',
    'grade-1': 'text-grade-1',
    'grade-2': 'text-grade-2',
    'grade-3': 'text-grade-3',
    'grade-4': 'text-grade-4',
    'grade-5': 'text-grade-5',
    'grade-6': 'text-grade-6',
};

const BAR_TONES = {
    primary: 'bg-chart-1',
    success: 'bg-success',
    warning: 'bg-warning',
    destructive: 'bg-destructive',
    'grade-1': 'bg-grade-1',
    'grade-2': 'bg-grade-2',
    'grade-3': 'bg-grade-3',
    'grade-4': 'bg-grade-4',
    'grade-5': 'bg-grade-5',
    'grade-6': 'bg-grade-6',
};

/**
 * Dashboard KPI tile.
 *
 * `trend` takes `{ direction: 'up' | 'down', label }`. Direction only sets the
 * arrow and colour — whether "up" is good is the caller's business, so the
 * label carries the meaning.
 *
 * `tone` colours the icon tile. The headline number itself stays in the
 * foreground colour: it is the thing being read, and tinting it costs
 * contrast for no added meaning.
 */
export function StatCard({
    label,
    value,
    icon: Icon,
    hint,
    trend,
    tone = 'primary',
    className,
}) {
    const TrendIcon = trend?.direction === 'down' ? ArrowDown : ArrowUp;

    return (
        <Card className={cn('p-4', className)}>
            <div className="flex items-start justify-between gap-3">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    {label}
                </p>
                {Icon && (
                    <span
                        className={cn(
                            'grid h-9 w-9 shrink-0 place-items-center rounded-lg',
                            ICON_TONES[tone] ?? ICON_TONES.primary,
                        )}
                    >
                        <Icon className="h-[18px] w-[18px]" aria-hidden="true" />
                    </span>
                )}
            </div>

            <p className="mt-2 truncate text-[26px] font-semibold tabular-nums leading-tight text-foreground">
                {value}
            </p>

            {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}

            {trend && (
                <p
                    className={cn(
                        'mt-1 flex items-center gap-1 text-xs',
                        trend.direction === 'down' ? 'text-destructive' : 'text-success',
                    )}
                >
                    <TrendIcon className="h-3 w-3" aria-hidden="true" />
                    {trend.label}
                </p>
            )}
        </Card>
    );
}

/**
 * A tile whose headline is a share of something — the bar makes the proportion
 * readable without doing arithmetic.
 */
export function MeterCard({
    label,
    value,
    percent,
    icon: Icon,
    hint,
    badge,
    tone,
    iconTone = 'primary',
    className,
}) {
    const clamped = Math.max(0, Math.min(100, percent ?? 0));

    return (
        <Card className={cn('p-4', className)}>
            <div className="flex items-start justify-between gap-3">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    {label}
                </p>
                {Icon && (
                    <span
                        className={cn(
                            'grid h-9 w-9 shrink-0 place-items-center rounded-lg',
                            ICON_TONES[iconTone] ?? ICON_TONES.primary,
                        )}
                    >
                        <Icon className="h-[18px] w-[18px]" aria-hidden="true" />
                    </span>
                )}
            </div>

            <p className="mt-2 flex items-baseline gap-2">
                <span className="text-[26px] font-semibold tabular-nums leading-tight text-foreground">
                    {value}
                </span>
                {badge && (
                    <span
                        className={cn(
                            'text-xs font-medium',
                            TEXT_TONES[tone] ?? TEXT_TONES.primary,
                        )}
                    >
                        {badge}
                    </span>
                )}
            </p>

            <span
                className="mt-2.5 block h-1.5 w-full overflow-hidden rounded-full bg-muted"
                role="img"
                aria-label={`${clamped}%`}
            >
                <span
                    className={cn(
                        'block h-full rounded-r-[4px] transition-[width] duration-500',
                        BAR_TONES[tone] ?? BAR_TONES.primary,
                    )}
                    style={{ width: `${clamped}%` }}
                />
            </span>

            {hint && <p className="mt-1.5 text-xs text-muted-foreground">{hint}</p>}
        </Card>
    );
}

/**
 * A tile holding two or three related counts, for figures that only mean
 * something beside each other.
 */
export function SplitStatCard({ label, icon: Icon, stats = [], tone = 'primary', className }) {
    return (
        <Card className={cn('p-4', className)}>
            <div className="flex items-start justify-between gap-3">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    {label}
                </p>
                {Icon && (
                    <span
                        className={cn(
                            'grid h-9 w-9 shrink-0 place-items-center rounded-lg',
                            ICON_TONES[tone] ?? ICON_TONES.primary,
                        )}
                    >
                        <Icon className="h-[18px] w-[18px]" aria-hidden="true" />
                    </span>
                )}
            </div>

            <dl className="mt-2 flex items-end justify-between gap-3">
                {stats.map((stat) => (
                    <div
                        key={stat.label}
                        className="min-w-0 flex-1 text-center first:text-left last:text-right"
                    >
                        <dd
                            className={cn(
                                'text-[26px] font-semibold tabular-nums leading-tight',
                                // A zero carries no warning worth colouring —
                                // "0 absent" in red reads as a problem when it
                                // is the opposite.
                                stat.value === 0 || stat.value === '0'
                                    ? TEXT_TONES.muted
                                    : (TEXT_TONES[stat.tone] ?? TEXT_TONES.default),
                            )}
                        >
                            {stat.value}
                        </dd>
                        <dt className="truncate text-[11px] uppercase tracking-wide text-muted-foreground">
                            {stat.label}
                        </dt>
                    </div>
                ))}
            </dl>
        </Card>
    );
}
