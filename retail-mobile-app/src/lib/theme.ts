import { Platform } from 'react-native';

/**
 * Brand colors for places className can't reach: icon tints, spinners, navigation options and
 * the status bar. Values mirror tailwind.config.js (sampled from the logo / splash artwork).
 */
export const colors = {
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
  accent: '#03c08a',
  accentDark: '#027a58',
  canvas: '#f3f6fa',
  white: '#ffffff',

  ink: '#0b1b3b',
  muted: '#5f7192',
  faint: '#8496b4',
  line: '#d7e1f0',

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
