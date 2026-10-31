/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./src/**/*.{js,jsx,ts,tsx}'],
  presets: [require('nativewind/preset')],
  theme: {
    extend: {
      colors: {
        // Miles Pharmacy POS palette, sampled from the app icon: its royal blue (600) is the main hue,
        // 400 its sky-blue highlight, 900-950 the same blue deepened; accent is the icon's green pill.
        // Keep src/lib/theme.ts in sync — it carries the same values for icons and navigation.
        brand: {
          50: '#eef7ff',
          100: '#d6ecff',
          200: '#a9d7fe',
          300: '#62b9fe',
          400: '#0ea0fe',
          500: '#0a7cee',
          600: '#0057db',
          700: '#0046b8',
          800: '#003a96',
          900: '#002f78',
          950: '#001f52',
        },
        // The icon's green (the pill and offline badge): the second brand colour, for go/money actions and highlights.
        // 700 is the shade for white text (buttons); DEFAULT reads on the navy backgrounds.
        accent: {
          50: '#e8faf3',
          100: '#c6f3e3',
          300: '#5fdcb3',
          DEFAULT: '#03c08a',
          600: '#029e72',
          700: '#027a58',
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
