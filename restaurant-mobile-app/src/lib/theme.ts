import { Platform } from 'react-native';

/**
 * Brand colors for places className can't reach: icon tints, spinners, navigation options and
 * the status bar. Values mirror tailwind.config.js (sampled from the app icon: red, maroon, navy).
 */
export const colors = {
  brand: {
      50: '#fff4f0',
      100: '#ffe3d9',
      200: '#ffc4b0',
      300: '#ff977a',
      400: '#e9502a',
      500: '#c92e0b',
      600: '#a41f01',
      700: '#841201',
      800: '#6e0e01',
      900: '#580b01',
      950: '#3a0701',
  },
  accent: '#1d3a66',
  accentDark: '#06172d',
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
