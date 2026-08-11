import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** Semantic token -> `hsl(var(--token) / <alpha>)` so opacity modifiers keep working. */
const token = (name) => `hsl(var(--${name}) / <alpha-value>)`;

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },

            colors: {
                background: token('background'),
                foreground: token('foreground'),

                card: {
                    DEFAULT: token('card'),
                    foreground: token('card-foreground'),
                },
                popover: {
                    DEFAULT: token('popover'),
                    foreground: token('popover-foreground'),
                },
                primary: {
                    DEFAULT: token('primary'),
                    foreground: token('primary-foreground'),
                },
                secondary: {
                    DEFAULT: token('secondary'),
                    foreground: token('secondary-foreground'),
                },
                muted: {
                    DEFAULT: token('muted'),
                    foreground: token('muted-foreground'),
                },
                accent: {
                    DEFAULT: token('accent'),
                    foreground: token('accent-foreground'),
                },
                destructive: {
                    DEFAULT: token('destructive'),
                    foreground: token('destructive-foreground'),
                },

                success: token('success'),
                warning: {
                    DEFAULT: token('warning'),
                    foreground: token('warning-foreground'),
                },
                info: token('info'),

                // Fixed-alpha per the spec's rgba() border values.
                border: 'hsl(var(--border) / var(--border-opacity))',
                input: 'hsl(var(--input) / var(--input-opacity))',
                ring: token('ring'),
                switch: token('switch'),

                sidebar: {
                    DEFAULT: token('sidebar'),
                    foreground: token('sidebar-foreground'),
                    primary: token('sidebar-primary'),
                    'primary-foreground': token('sidebar-primary-foreground'),
                    accent: token('sidebar-accent'),
                    'accent-foreground': token('sidebar-accent-foreground'),
                    muted: token('sidebar-muted'),
                    border: 'hsl(var(--sidebar-border) / var(--border-opacity))',
                },

                logo: {
                    primary: token('logo-primary'),
                    subtitle: token('logo-subtitle'),
                },

                chart: {
                    1: token('chart-1'),
                    2: token('chart-2'),
                    3: token('chart-3'),
                    4: token('chart-4'),
                },
            },

            borderRadius: {
                lg: 'var(--radius)',
                md: 'calc(var(--radius) - 2px)',
                sm: 'calc(var(--radius) - 4px)',
            },

            spacing: {
                // Sidebar rails: 64px collapsed / 260px expanded.
                sidebar: '260px',
                'sidebar-collapsed': '64px',
                0.75: '3px',
                4.5: '1.125rem',
            },

            keyframes: {
                'accordion-down': {
                    from: { height: '0', opacity: '0' },
                    to: { height: 'var(--radix-accordion-content-height)', opacity: '1' },
                },
                'fade-in': {
                    from: { opacity: '0' },
                    to: { opacity: '1' },
                },
                'slide-up': {
                    from: { opacity: '0', transform: 'translateY(6px)' },
                    to: { opacity: '1', transform: 'translateY(0)' },
                },
            },

            animation: {
                'fade-in': 'fade-in 150ms ease-out',
                'slide-up': 'slide-up 200ms ease-out',
            },
        },
    },

    plugins: [forms],
};
