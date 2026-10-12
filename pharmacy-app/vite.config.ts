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

// The product is served at {workspace}/<path>. Built files go to
// public/<path>/build, so /<path>/build/... is a real file and never the
// platform's own /build/ (AppServiceProvider points Laravel there too).
const path = env.PLATFORM_PATH || env.PLATFORM_APPLICATION || 'pharmacy';

// Pages on every workspace host load scripts from the dev server, and
// module scripts need CORS.
const rootDomain = (
    env.PLATFORM_ROOT_DOMAIN || 'milessoftwares.localhost'
).replaceAll('.', '\\.');
const platformOrigin = new RegExp(
    `^https?://([a-z0-9-]+\\.)?${rootDomain}(:\\d+)?$`,
);

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            buildDirectory: `${path}/build`,
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
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
        // DEV_SERVER_HOST: listen on the address the platform hosts resolve to
        // (browsers block a LAN-address page from loading loopback assets).
        host: env.DEV_SERVER_HOST || undefined,
        port: env.DEV_SERVER_PORT ? Number(env.DEV_SERVER_PORT) : undefined,
        strictPort: Boolean(env.DEV_SERVER_PORT),
        cors: { origin: platformOrigin },
        watch: {
            ignored: ['**/.claude/**', '**/.cursor/**', '**/vendor/**'],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
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
            'composer.json',
            'resources/js/components/ui/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
