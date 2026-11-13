import { Platform } from 'react-native';

/**
 * Brand colors for places className can't reach: icon tints, spinners, navigation options and
 * the status bar. Values mirror tailwind.config.js (the icon's navy, fuel orange, navy neutrals).
 */
export const colors = {
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
  accent: '#fd9800',
  accentDark: '#b45309',
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
