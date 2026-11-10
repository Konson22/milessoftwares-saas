import { Platform } from 'react-native';

/**
 * Brand colors for places className can't reach: icon tints, spinners, navigation options and
 * the status bar. Values mirror tailwind.config.js (fuel orange, pump yellow, navy neutrals).
 */
export const colors = {
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
      logo: '#b83a0a',
  },
  accent: '#facc15',
  accentDark: '#ca8a04',
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
