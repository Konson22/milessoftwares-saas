import { Platform } from 'react-native';

/**
 * Brand colors for places className can't reach: icon tints, spinners, navigation options and
 * the status bar. Values mirror tailwind.config.js (sampled from the logo / splash artwork).
 */
export const colors = {
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
  accent: '#10a38a',
  accentDark: '#0b6f5e',
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
