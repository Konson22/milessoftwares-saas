/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./src/**/*.{js,jsx,ts,tsx}'],
  presets: [require('nativewind/preset')],
  theme: {
    extend: {
      colors: {
        // Mataem Plus palette, sampled from the app icon: its red (600) is the main hue, 500 its bright
        // highlight, 700-800 the maroon shadows, 900-950 the same red deepened for the dark backdrops.
        // Keep src/lib/theme.ts in sync — it carries the same values for icons and navigation.
        brand: {
          50: '#fff4f0',
          100: '#ffe3d9',
          200: '#ffc4b0',
          300: '#ff977a',
          400: '#e9502a',
          500: '#c92e0b',
          600: '#a41f01',
          700: '#841201',
          800: '#6e0e01',
          900: '#580b01',
          950: '#3a0701',
          // The red behind the chef's hat in the app icon, exactly: the app bars.
          logo: '#8f0101',
        },
        // The icon's orange (the arc round the plate): the second brand colour, for payment actions and
        // markers. Dark text on it (brand-950); DEFAULT for buttons, bars and borders on white or red.
        accent: {
          50: '#fff7e6',
          100: '#ffebc2',
          300: '#ffc95c',
          DEFAULT: '#ffa000',
          600: '#f08f00',
          700: '#d97a00',
        },
        canvas: '#f3f6fa',
        // Slate tuned toward the icon's navy, so the neutrals sit on the accent's hue.
        slate: {
          50: '#f5f8fd',
          100: '#e9eff8',
          200: '#d7e1f0',
          300: '#b9c8de',
          400: '#8496b4',
          500: '#5f7192',
          600: '#475a7a',
          700: '#34466a',
          800: '#1d2e52',
          900: '#0b1b3b',
        },
        ink: {
          DEFAULT: '#0b1b3b',
          muted: '#5f7192',
          faint: '#8496b4',
        },
      },
    },
  },
  plugins: [],
};
