import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

/*
 * Vite build config for the standalone (non-Filament) pages.
 *
 * NOTE ON FONTS: this file used to declare
 *
 *     import { bunny } from 'laravel-vite-plugin/fonts';
 *     ... fonts: [bunny('Instrument Sans', { weights: [400, 500, 600] })]
 *
 * which made the browser fetch the font from fonts.bunny.net — an external
 * CDN. That was removed: the app must load zero third-party resources, and
 * the only font it uses (Vazirmatn) is committed under public/fonts/ and
 * declared in public/css/vazirmatn.css. Do not add a `fonts:` option back.
 */
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            // Reload the browser automatically when Blade/PHP files change.
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            // Compiled Blade templates are regenerated constantly; watching
            // them would cause an endless reload loop.
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
