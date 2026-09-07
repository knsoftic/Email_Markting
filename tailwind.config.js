import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * KN Softic design system.
 *
 * `brand` is driven by CSS variables so the super admin's saved brand colour
 * changes the whole UI without a rebuild; the hex values in :root (app.css)
 * are only the fallback shipped with the product.
 */
/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', 'Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    50: 'rgb(var(--brand-50) / <alpha-value>)',
                    100: 'rgb(var(--brand-100) / <alpha-value>)',
                    200: 'rgb(var(--brand-200) / <alpha-value>)',
                    300: 'rgb(var(--brand-300) / <alpha-value>)',
                    400: 'rgb(var(--brand-400) / <alpha-value>)',
                    500: 'rgb(var(--brand-500) / <alpha-value>)',
                    600: 'rgb(var(--brand-600) / <alpha-value>)',
                    700: 'rgb(var(--brand-700) / <alpha-value>)',
                    800: 'rgb(var(--brand-800) / <alpha-value>)',
                    900: 'rgb(var(--brand-900) / <alpha-value>)',
                },
                accent: {
                    500: 'rgb(var(--accent-500) / <alpha-value>)',
                    600: 'rgb(var(--accent-600) / <alpha-value>)',
                },
                ink: {
                    50: '#f8fafc',
                    100: '#f1f5f9',
                    200: '#e2e8f0',
                    300: '#cbd5e1',
                    400: '#94a3b8',
                    500: '#64748b',
                    600: '#475569',
                    700: '#334155',
                    800: '#1e293b',
                    900: '#0f172a',
                },
            },
            boxShadow: {
                card: '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 1px 3px 0 rgb(15 23 42 / 0.06)',
                'card-hover': '0 4px 12px -2px rgb(15 23 42 / 0.10)',
                panel: '0 10px 30px -12px rgb(15 23 42 / 0.25)',
            },
            borderRadius: {
                xl: '0.75rem',
                '2xl': '1rem',
            },
            keyframes: {
                'fade-in': {
                    '0%': { opacity: '0', transform: 'translateY(4px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                shimmer: {
                    '100%': { transform: 'translateX(100%)' },
                },
            },
            animation: {
                'fade-in': 'fade-in 0.18s ease-out',
                shimmer: 'shimmer 1.4s infinite',
            },
        },
    },

    plugins: [forms],
};
