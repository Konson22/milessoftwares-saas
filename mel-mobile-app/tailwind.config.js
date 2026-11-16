/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./src/**/*.{js,jsx,ts,tsx}'],
  presets: [require('nativewind/preset')],
  theme: {
    extend: {
      colors: {
        // Miles Petrol palette, from the app icon: navy (the icon's tile) is the main hue, 600 for
        // primary buttons (white text), 900-950 for the dark backdrops; logo is the tile exactly, for
        // the app bars, so the mark sits on them without an edge.
        // Keep src/lib/theme.ts in sync — it carries the same values for icons and navigation.
        brand: {
          50: '#eef4fb',
          100: '#d9e6f5',
          200: '#b6cdea',
          300: '#86a9d8',
          400: '#4f7fc0',
          500: '#2a5ea6',
          600: '#1a4a8c',
          700: '#123b74',
          800: '#0b3062',
          900: '#052a5a',
          950: '#011c40',
          logo: '#002657',
        },
        // Fuel orange, the icon's drop and nozzle: the second brand colour, for payment actions,
        // sign-in and markers. Dark text on it (brand-950); DEFAULT for buttons, bars and borders.
        accent: {
          50: '#fff7ed',
          100: '#ffedd5',
          300: '#fdba74',
          DEFAULT: '#fd9800',
          600: '#ea7c00',
          700: '#b45309',
        },
        canvas: '#f3f6fa',
        // Slate tuned toward navy, so the neutrals sit on the accent's hue.
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
