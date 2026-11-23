/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./src/**/*.{js,jsx,ts,tsx}'],
  presets: [require('nativewind/preset')],
  theme: {
    extend: {
      colors: {
        // Tatook Logbook palette: ledger blue for the main hue (600 for primary buttons with
        // white text, 900-950 for the dark backdrops; logo is the deep blue of the app bars and
        // the splash), amber like a pencil mark for the main action on dark surfaces.
        // Solid colours only: no transparency, no gradients.
        // Keep src/lib/theme.ts in sync: it carries the same values for icons and navigation.
        brand: {
          50: '#eef1fb',
          100: '#dde3f6',
          200: '#bcc7ec',
          300: '#8fa0dc',
          400: '#6178c8',
          500: '#4058b3',
          600: '#2f4699',
          700: '#27397c',
          800: '#212f63',
          900: '#1b264f',
          950: '#111833',
          logo: '#1d2a5c',
        },
        accent: {
          50: '#fff8eb',
          100: '#feeccb',
          300: '#fbcf72',
          DEFAULT: '#f2a516',
          600: '#d98a07',
          700: '#a86506',
        },
        canvas: '#f4f5f8',
        slate: {
          50: '#f6f7f9',
          100: '#eceef3',
          200: '#d9dde6',
          300: '#b9c0ce',
          400: '#8b94a8',
          500: '#636c80',
          600: '#4a5265',
          700: '#363d4d',
          800: '#232936',
          900: '#13171f',
        },
        ink: {
          DEFAULT: '#13171f',
          muted: '#636c80',
          faint: '#8b94a8',
        },
      },
    },
  },
  plugins: [],
};
