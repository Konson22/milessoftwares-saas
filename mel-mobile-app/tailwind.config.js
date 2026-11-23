/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./src/**/*.{js,jsx,ts,tsx}'],
  presets: [require('nativewind/preset')],
  theme: {
    extend: {
      colors: {
        // Miles MDC palette, from the app icon: the tile's teal-blue is the main hue, 600 for
        // primary buttons (white text), 900-950 for the dark backdrops; logo is the deep teal of
        // the app bars and the splash.
        // Keep src/lib/theme.ts in sync — it carries the same values for icons and navigation.
        brand: {
          50: '#e8f6f8',
          100: '#cceaf0',
          200: '#9dd5e0',
          300: '#63b9cc',
          400: '#2a9ab3',
          500: '#0a7f9c',
          600: '#036b86',
          700: '#04586f',
          800: '#07485b',
          900: '#093c4c',
          950: '#052733',
          logo: '#04506a',
        },
        // The location pin's green: the second brand colour, for the main action on dark
        // surfaces and the progress bar. Dark text on it (brand-950).
        accent: {
          50: '#effbea',
          100: '#dcf6d3',
          300: '#a9ec98',
          DEFAULT: '#6fdc5c',
          600: '#4fc23e',
          700: '#2f8a22',
        },
        canvas: '#f3f7f8',
        // Slate tuned toward teal, so the neutrals sit on the brand's hue.
        slate: {
          50: '#f5f8f9',
          100: '#e8eff1',
          200: '#d5e1e5',
          300: '#b5c7cd',
          400: '#8299a1',
          500: '#5d737b',
          600: '#465a62',
          700: '#33464d',
          800: '#1e2f35',
          900: '#0d1d22',
        },
        ink: {
          DEFAULT: '#0d1d22',
          muted: '#5d737b',
          faint: '#8299a1',
        },
      },
    },
  },
  plugins: [],
};
