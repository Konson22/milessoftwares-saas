import { Platform } from 'react-native';

/**
 * Brand colors for places className can't reach: icon tints, spinners, navigation options and
 * the status bar. Values mirror tailwind.config.js (the icon's teal-blue, the pin's green, teal neutrals).
 */
export const colors = {
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
  accent: '#6fdc5c',
  accentDark: '#2f8a22',
  canvas: '#f3f7f8',
  white: '#ffffff',

  ink: '#0d1d22',
  muted: '#5d737b',
  faint: '#8299a1',
  line: '#d5e1e5',

  // Status colors keep their conventional meaning (green = ok, amber = attention, red = danger).
  success: '#059669',
  successDark: '#047857',
  warning: '#b45309',
  danger: '#dc2626',
  dangerDark: '#b91c1c',
} as const;

/**
 * For a TextInput whose container draws the focus state: drops the browser's own outline on web.
 * `outlineStyle: 'none'` is web-only and missing from RN's native style types, hence the cast.
 */
export const webNoOutline = Platform.OS === 'web' ? ({ outlineStyle: 'none' } as object) : undefined;
