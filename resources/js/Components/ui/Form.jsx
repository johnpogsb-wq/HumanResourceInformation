import { forwardRef, useId } from 'react';
import { ChevronDown, Search } from 'lucide-react';
import { cn } from '@/lib/utils';

const FIELD_BASE =
    'w-full rounded-md border border-input bg-background text-foreground placeholder:text-muted-foreground/70 ' +
    'shadow-sm transition-colors focus:border-primary focus:ring-2 focus:ring-ring/30 ' +
    'disabled:cursor-not-allowed disabled:opacity-60';

export function Label({ className, required, children, ...props }) {
    return (
        <label
            className={cn('mb-1.5 block text-xs font-medium text-foreground', className)}
            {...props}
        >
            {children}
            {required && <span className="ml-0.5 text-destructive">*</span>}
        </label>
    );
}

export function InputError({ message, className }) {
    if (!message) return null;

    return <p className={cn('mt-1 text-xs text-destructive', className)}>{message}</p>;
}

export const Input = forwardRef(function Input({ className, error, ...props }, ref) {
    return (
        <input
            ref={ref}
            className={cn(
                FIELD_BASE,
                'h-9 px-3 text-sm',
                error &&
                    'border-destructive focus:border-destructive focus:ring-destructive/30',
                className,
            )}
            aria-invalid={error ? 'true' : undefined}
            {...props}
        />
    );
});

export const Textarea = forwardRef(function Textarea(
    { className, error, rows = 3, ...props },
    ref,
) {
    return (
        <textarea
            ref={ref}
            rows={rows}
            className={cn(
                FIELD_BASE,
                'px-3 py-2 text-sm',
                error &&
                    'border-destructive focus:border-destructive focus:ring-destructive/30',
                className,
            )}
            aria-invalid={error ? 'true' : undefined}
            {...props}
        />
    );
});

export const Select = forwardRef(function Select(
    { className, error, options = [], placeholder, children, ...props },
    ref,
) {
    return (
        <div className="relative">
            <select
                ref={ref}
                className={cn(
                    FIELD_BASE,
                    'h-9 appearance-none py-0 pl-3 pr-9 text-sm',
                    error &&
                        'border-destructive focus:border-destructive focus:ring-destructive/30',
                    className,
                )}
                aria-invalid={error ? 'true' : undefined}
                {...props}
            >
                {placeholder && <option value="">{placeholder}</option>}
                {children ??
                    options.map((option) => {
                        const value = typeof option === 'string' ? option : option.value;
                        const label = typeof option === 'string' ? option : option.label;

                        return (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        );
                    })}
            </select>
            <ChevronDown
                className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
        </div>
    );
});

export const SearchInput = forwardRef(function SearchInput({ className, ...props }, ref) {
    return (
        <div className="relative">
            <Search
                className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input ref={ref} type="search" className={cn('pl-9', className)} {...props} />
        </div>
    );
});

/** Label + control + error, wired together with a generated id. */
export function Field({ label, required, error, hint, className, children }) {
    const id = useId();

    return (
        <div className={cn('min-w-0', className)}>
            {label && (
                <Label htmlFor={id} required={required}>
                    {label}
                </Label>
            )}
            {typeof children === 'function' ? children({ id, error }) : children}
            {hint && !error && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}
