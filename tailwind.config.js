import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * Semantic colour tokens backed by CSS variables (resources/css/app.css),
 * so dark mode is a single switch and views never use raw palette colours.
 */
const token = (name) => `rgb(var(--${name}) / <alpha-value>)`;

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
                sans: ['"Plus Jakarta Sans Variable"', ...defaultTheme.fontFamily.sans],
                display: ['"Outfit Variable"', '"Plus Jakarta Sans Variable"', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                canvas: token('canvas'),
                surface: token('surface'),
                'surface-2': token('surface-2'),
                line: token('line'),
                ink: token('ink'),
                'ink-2': token('ink-2'),
                'ink-3': token('ink-3'),
                primary: {
                    DEFAULT: token('primary'),
                    hover: token('primary-hover'),
                    ink: token('primary-ink'),
                    soft: token('primary-soft'),
                },
                marker: { DEFAULT: token('marker'), ink: token('marker-ink') },
                success: { DEFAULT: token('success'), soft: token('success-soft') },
                warning: { DEFAULT: token('warning'), soft: token('warning-soft') },
                danger: { DEFAULT: token('danger'), soft: token('danger-soft') },
            },
            ringColor: {
                DEFAULT: token('primary'),
            },
            transitionDuration: {
                DEFAULT: '150ms',
            },
        },
    },

    plugins: [forms],
};
