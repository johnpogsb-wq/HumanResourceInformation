import { cn } from '@/lib/utils';

/** Logo mark — a stylised road/route chevron for Fleet & Transportation. */
export function LogoMark({ className }) {
    return (
        <svg
            viewBox="0 0 32 32"
            className={cn('h-8 w-8 shrink-0', className)}
            role="img"
            aria-label="PrimePower Manpower"
        >
            <rect width="32" height="32" rx="8" className="fill-logo-primary" />
            <path d="M10 23 L16 9 L22 23 L16 19.5 Z" className="fill-primary-foreground" />
        </svg>
    );
}

export default function PrimePowerLogo({ collapsed = false, className }) {
    return (
        <div className={cn('flex items-center gap-2.5 overflow-hidden', className)}>
            <LogoMark />

            <div
                className={cn(
                    'min-w-0 transition-[opacity,width] duration-300',
                    collapsed ? 'w-0 opacity-0' : 'w-auto opacity-100',
                )}
                aria-hidden={collapsed}
            >
                <p className="truncate text-[13px] font-bold leading-tight tracking-tight text-logo-primary">
                    PRIMEPOWER MANPOWER
                </p>
                <p className="truncate text-[10px] font-medium leading-tight text-logo-subtitle">
                    Fleet &amp; Transportation Mgmt.
                </p>
            </div>
        </div>
    );
}
