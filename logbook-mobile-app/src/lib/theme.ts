import { Platform } from 'react-native';

/**
 * Brand colors for places className can't reach: icon tints, spinners, navigation options and
 * the status bar. Values mirror tailwind.config.js (ledger blue, pencil amber, cool neutrals).
 */
export const colors = {
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
  accent: '#f2a516',
  accentDark: '#a86506',
  canvas: '#f4f5f8',
  white: '#ffffff',

  ink: '#13171f',
  muted: '#636c80',
  faint: '#8b94a8',
  line: '#d9dde6',

  // Status colors keep their conventional meaning (green = ok, amber = attention, red = danger).
  success: '#059669',
  successDark: '#047857',
  warning: '#b45309',
  danger: '#dc2626',
  dangerDark: '#b91c1c',
} as const;

/**
 * Function colours for icon tiles (quick actions, list rows), so each part of the app is known
 * by its colour at a glance. `solid` fills a tile behind a white icon; `soft` + `fg` make the
 * light tile with a coloured icon used in lists. Solid colours only.
 */
export const tiles = {
  blue: { solid: '#2f4699', soft: '#e3e8f8', fg: '#2f4699' },
  amber: { solid: '#e8930c', soft: '#fdf0d5', fg: '#a86506' },
  green: { solid: '#0f9d6b', soft: '#dcf3e9', fg: '#047857' },
  violet: { solid: '#7048d8', soft: '#ece6fb', fg: '#5b34c2' },
  teal: { solid: '#0e8f8a', soft: '#d9f1ef', fg: '#0b6f6b' },
  red: { solid: '#d93636', soft: '#fbe3e3', fg: '#b91c1c' },
  sky: { solid: '#1f87d6', soft: '#dceefb', fg: '#16639e' },
  orange: { solid: '#e4622a', soft: '#fce6db', fg: '#b6461a' },
  slate: { solid: '#636c80', soft: '#eceef3', fg: '#4a5265' },
} as const;

export type TileColor = keyof typeof tiles;

/**
 * For a TextInput whose container draws the focus state: drops the browser's own outline on web.
 * `outlineStyle: 'none'` is web-only and missing from RN's native style types, hence the cast.
 */
export const webNoOutline = Platform.OS === 'web' ? ({ outlineStyle: 'none' } as object) : undefined;
