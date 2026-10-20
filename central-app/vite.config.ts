import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { loadEnv } from 'vite';
import { defineConfig, lazyPlugins } from 'vite-plus';

const env = loadEnv(process.env.NODE_ENV ?? 'development', process.cwd(), '');

// Pages on every platform host (apex, auth., app., admin., workspaces) load
// scripts from the dev server, and module scripts need CORS.
const rootDomain = (
    env.PLATFORM_ROOT_DOMAIN || 'milesoftwares.localhost'
).replaceAll('.', '\\.');
const platformOrigin = new RegExp(
    `^https?://([a-z0-9-]+\\.)?${rootDomain}(:\\d+)?$`,
);

export default defineConfig({
    // @miles/ui (../packages/ui) is linked into node_modules: keep its path
    // there so its imports (React, Inertia) resolve to this app's copies.
    resolve: {
        preserveSymlinks: true,
    },
    // Served as source, never pre-bundled, so edits to it show up at once.
    optimizeDeps: {
        exclude: ['@miles/ui'],
    },
    // The SSR bundle loads node_modules at runtime; the package is TSX
    // source, so it is bundled instead.
    ssr: {
        noExternal: ['@miles/ui'],
    },
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                // Signed-in apps (@miles/ui theme).
                bunny('Inter', {
                    weights: [400, 500, 600, 700],
                }),
                // Public site.
                bunny('IBM Plex Sans', {
                    weights: [400, 500, 600, 700],
                }),
            ],
        }),
        inertia(),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ]),
    server: {
        // DEV_SERVER_HOST: the address to listen on (0.0.0.0 for every one).
        // DEV_SERVER_PUBLIC_HOST: the name pages load scripts from, e.g.
        // milesoftwares.localhost. It resolves to the same address as the page
        // (browsers block a LAN-address page from loading loopback assets)
        // and follows the hosts file when the machine's IP changes.
        host: env.DEV_SERVER_HOST || undefined,
        port: env.DEV_SERVER_PORT ? Number(env.DEV_SERVER_PORT) : undefined,
        origin:
            env.DEV_SERVER_PUBLIC_HOST && env.DEV_SERVER_PORT
                ? `http://${env.DEV_SERVER_PUBLIC_HOST}:${env.DEV_SERVER_PORT}`
                : undefined,
        hmr: env.DEV_SERVER_PUBLIC_HOST
            ? { host: env.DEV_SERVER_PUBLIC_HOST }
            : undefined,
        strictPort: Boolean(env.DEV_SERVER_PORT),
        cors: { origin: platformOrigin },
        watch: {
            // Watch @miles/ui (linked into node_modules) so edits reload.
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
                '!**/node_modules/@miles/ui/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/shared/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            'README.md',
            '.github/**',
            'composer.json',
            'resources/js/shared/components/ui/*',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
