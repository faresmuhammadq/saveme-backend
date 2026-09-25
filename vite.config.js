import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'app/Modules/Admin/Http/Assets/admin.css',
                'app/Modules/Admin/Http/Assets/admin.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                // Back office (spec 006, R-6): self-hosted at build time.
                bunny('Hanken Grotesk', {
                    weights: [400, 500, 600, 700, 800],
                    subsets: ['latin', 'latin-ext'],
                    preload: false,
                }),
                bunny('IBM Plex Sans Arabic', {
                    weights: [400, 500, 600, 700],
                    subsets: ['arabic'],
                    preload: false,
                }),
            ],
        }),
        tailwindcss(),
    ]),
    server: {
        cors: true,
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/storage/framework/views/**',
                '**/vendor/**',
            ],
        },
    },
});
