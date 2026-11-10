/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./src/**/*.{js,jsx,ts,tsx}'],
  presets: [require('nativewind/preset')],
  theme: {
    extend: {
      colors: {
        // Miles Petrol palette: fuel orange (600, white text reads on it) is the main hue, 500 its bright
        // highlight, 700-800 the burnt shadows, 900-950 the same orange deepened for the dark backdrops.
        // Keep src/lib/theme.ts in sync — it carries the same values for icons and navigation.
        brand: {
          50: '#fff7ed',
          100: '#ffedd5',
          200: '#fed7aa',
          300: '#fdba74',
          400: '#f97316',
          500: '#ea580c',
          600: '#c2410c',
          700: '#9a3412',
          800: '#7c2d12',
          900: '#5c2410',
          950: '#3b1608',
          // The app icon's orange, exactly: the app bars.
          logo: '#b83a0a',
        },
        // Pump yellow: the second brand colour, for payment actions and
        // markers. Dark text on it (brand-950); DEFAULT for buttons, bars and borders on white or orange.
        accent: {
          50: '#fefce8',
          100: '#fef9c3',
          300: '#fde047',
          DEFAULT: '#facc15',
          600: '#eab308',
          700: '#ca8a04',
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
