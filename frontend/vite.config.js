import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import path from 'node:path';

/*
 * The frontend builds here and writes its output into the backend.
 *
 * That crossing is the honest shape of this project: the two folders separate
 * the *dependencies* (composer in `backend/`, npm here) but the app is still
 * one Inertia deployment — Laravel serves the pages and reads the compiled
 * assets out of its own `public/` directory through the `@vite()` directive in
 * `backend/resources/views/app.blade.php`. Splitting the folders does not
 * change that, and pretending otherwise by emitting to a local `dist/` would
 * just mean copying files across afterwards.
 *
 * `landing/` next door is the genuinely separate one: its own Vite config has
 * no `laravel-vite-plugin` at all and emits a standalone `dist/`.
 */
export default defineConfig({
    plugins: [
        laravel({
            /*
             * Relative to this folder, so the built manifest keys stay
             * `resources/js/...` exactly as before the split — which is why
             * the blade's `@vite(['resources/js/app.jsx', …])` and all 211
             * per-page entries keep resolving untouched. Renaming this to
             * `src/` would have rewritten every key in the manifest and
             * broken the blade on the first page load.
             */
            input: 'resources/js/app.jsx',

            /*
             * Where Laravel looks for `build/manifest.json` and the `hot`
             * file. Points across the split at the backend's public
             * directory; without this the manifest would land in
             * `frontend/public/` where Laravel never looks.
             */
            publicDirectory: '../backend/public',

            /*
             * Stated rather than left at `true`. The plugin's defaults watch
             * `resources/views/**` relative to *this* folder, which no longer
             * holds any blade files — they are in the backend now, so a saved
             * blade would silently stop triggering a reload.
             */
            refresh: [
                '../backend/resources/views/**',
                '../backend/routes/**',
                '../backend/app/Http/Controllers/**',
            ],
        }),
        react(),
    ],

    /*
     * Vite skips emptying the output directory when it sits outside the
     * project root — a safety default, and since the split the output *is*
     * outside (`../backend/public/build`). Left off, every build layers new
     * hashed filenames on top of the previous ones: the first build after the
     * move left 218 files behind a manifest that referenced 109, and all 109
     * dead ones were staged for commit. Stated explicitly because the default
     * is the wrong one for this layout.
     */
    build: {
        emptyOutDir: true,
    },

    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
});
