import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

/** Drop the company logo here and it is picked up automatically. */
const LOGO_SRC = '/images/logo.png';

/**
 * The logo mark.
 *
 * Uses the real artwork when it is present and falls back to a drawn mark
 * otherwise, so a missing file degrades to something sensible rather than a
 * broken-image icon.
 */
export function LogoMark({ className }) {
    const [failed, setFailed] = useState(false);

    if (!failed) {
        return (
            <img
                src={LOGO_SRC}
                alt=""
                onError={() => setFailed(true)}
                className={cn('h-7 w-7 shrink-0 object-contain', className)}
            />
        );
    }

    return (
        <svg
            viewBox="0 0 32 32"
            className={cn('h-7 w-7 shrink-0', className)}
            role="img"
            aria-label="PrimePower"
        >
            <rect width="32" height="32" rx="8" className="fill-logo-primary" />
            <path d="M10 23 L16 9 L22 23 L16 19.5 Z" className="fill-primary-foreground" />
        </svg>
    );
}

export default function PrimePowerLogo({ collapsed = false, className }) {
    // Brand text is shared from Settings > General, so changing it there
    // changes it everywhere at once.
    const brand = usePage().props.brand ?? {};

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
                    {(brand.name ?? 'PrimePower').toUpperCase()}
                </p>
                <p className="truncate text-[10px] font-medium leading-tight text-logo-subtitle">
                    {brand.tagline ?? 'Human Resource Information System'}
                </p>
            </div>
        </div>
    );
}
