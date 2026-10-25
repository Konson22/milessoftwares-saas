/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./src/**/*.{js,jsx,ts,tsx}'],
  presets: [require('nativewind/preset')],
  theme: {
    extend: {
      colors: {
        // Miles Pharmacy POS palette, sampled from the logo: its blue (600) is the main hue,
        // 900-950 the same blue deepened; accent is the logo's teal-green.
        // Keep src/lib/theme.ts in sync — it carries the same values for icons and navigation.
        brand: {
          50: '#eef5ff',
          100: '#dbe8fe',
          200: '#bfd6fd',
          300: '#93bbfa',
          400: '#5b97f3',
          500: '#2f77e6',
          600: '#0b5bd3',
          700: '#0a4bb0',
          800: '#0c3f8e',
          900: '#0e3573',
          950: '#0a2350',
        },
        // The logo's teal-green (the mortar and leaf): the second brand colour, for go/money actions and highlights.
        accent: {
          50: '#e7f8f3',
          100: '#c8eee2',
          300: '#6dd0b4',
          DEFAULT: '#10a38a',
          600: '#0d8a74',
          700: '#0b6f5e',
        },
        canvas: '#f3f6fa',
        // Slate retuned toward the brand blue so every neutral in the app sits on the same hue.
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
