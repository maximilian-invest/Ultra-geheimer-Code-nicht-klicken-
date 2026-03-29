import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                display: ['Manrope', 'Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                background: 'var(--background)',
                foreground: 'var(--foreground)',
                card: { DEFAULT: 'var(--card)', foreground: 'var(--card-foreground)' },
                primary: { DEFAULT: 'var(--primary)', foreground: 'var(--primary-foreground)' },
                secondary: { DEFAULT: 'var(--secondary)', foreground: 'var(--secondary-foreground)' },
                muted: { DEFAULT: 'var(--muted)', foreground: 'var(--muted-foreground)' },
                accent: { DEFAULT: 'var(--accent)', foreground: 'var(--accent-foreground)' },
                destructive: { DEFAULT: 'var(--destructive)', foreground: '#fff' },
                border: 'var(--border)',
                input: 'var(--input)',
                ring: 'var(--ring)',
                brand: { DEFAULT: '#f97316', light: 'var(--brand-light)' },
            },
            borderRadius: {
                sm: 'calc(var(--radius) - 4px)',
                DEFAULT: 'calc(var(--radius) - 2px)',
                md: 'calc(var(--radius) - 2px)',
                lg: 'var(--radius)',
                xl: 'calc(var(--radius) + 4px)',
            },
        },
    },

    plugins: [forms],
};
