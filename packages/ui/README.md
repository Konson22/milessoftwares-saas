# @miles/ui

The signed-in look of every Miles Softwares app: the workspace, the account dashboard and
settings, the staff console, and each product (Pharmacy, Finance, and products made from
`templates/product-app`). One theme, one application shell, one set of components. The public
marketing site keeps its own look.

| Path | What |
| --- | --- |
| `theme.css` | Tokens: Inter, navy sidebar, grey canvas, white panels (light and dark) |
| `shell/app-shell.tsx` | The frame: sidebar (drawer on small screens), top bar with breadcrumbs, flash, main |
| `shell/sidebar.tsx` | `ShellSidebar`: way back, app name, sections; `external` items open another app |
| `shell/navigation.ts` | `ShellNavItem`/`ShellNavGroup`, `activeItem()`, `crumbsFor()` |
| `shell/quick-search.tsx` | Ctrl+K: sections plus the app's own searches |
| `shell/user-menu.tsx` | Who is signed in, links, sign out |
| `components/*` | `Panel`, `PageHeader`, `StatCard`, `StatusBadge`, `EmptyState`, `SearchField` |
| `components/ui/*` | shadcn primitives: button, badge, card, input, label, table |

The app supplies what only it knows (its sections, permissions, searches, sign-out route); the
package draws them. See any app's `resources/js/layouts/*` for a complete example.

## Using it in an app

It is TSX source, linked rather than built:

```jsonc
// package.json
"@miles/ui": "file:../packages/ui"
```

```ts
// vite.config.ts: imports inside the package resolve from the app's node_modules
resolve: { preserveSymlinks: true },
ssr: { noExternal: ['@miles/ui'] }, // only when the app builds an SSR bundle
```

```jsonc
// tsconfig.json, compilerOptions
"preserveSymlinks": true
```

```css
/* resources/css/app.css */
@import 'tailwindcss';
@import 'tw-animate-css';
@import '../../node_modules/@miles/ui/theme.css';
@source '../../node_modules/@miles/ui';
```

Its peer dependencies (React, Inertia, lucide-react, clsx, tailwind-merge, cva, Radix slot and
label) come from the app. Inside the package, imports are relative; never `@/`.

Change something here and every app gets it on its next build (in development, Vite picks it up
live). Check all apps after a change: `npm run types:check && npm run build` in each.
