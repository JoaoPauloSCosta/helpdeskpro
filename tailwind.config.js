import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            colors: {
                primary: {
                    50: '#EBF4FF',
                    100: '#D6E8FF',
                    200: '#ADD1FF',
                    300: '#7AB4FF',
                    400: '#3D93FF',
                    500: '#0075F2',
                    600: '#005FC4',
                    700: '#004A9A',
                    800: '#003672',
                    900: '#00234A',
                },
                app: { bg: '#FAFAFA' },
                surface: '#FFFFFF',
                neutral: {
                    50: '#FAFAFA',
                    100: '#F4F4F5',
                    200: '#E4E4E7',
                    300: '#D4D4D8',
                    400: '#A1A1AA',
                    500: '#71717A',
                    600: '#52525B',
                    700: '#3F3F46',
                    800: '#27272A',
                    900: '#18181B',
                },
                success: { bg: '#DCFCE7', border: '#86EFAC', text: '#166534', DEFAULT: '#16A34A' },
                warning: { bg: '#FEF3C7', border: '#FCD34D', text: '#92400E', DEFAULT: '#D97706' },
                danger: {
                    bg: '#FEE2E2',
                    border: '#FCA5A5',
                    text: '#991B1B',
                    DEFAULT: '#DC2626',
                    600: '#DC2626',
                    700: '#B91C1C',
                },
            },
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms],
};
