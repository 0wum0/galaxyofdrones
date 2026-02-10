const mix = require('laravel-mix');
const glob = require('glob');
const fs   = require('fs');
const path = require('path');

/*
 |--------------------------------------------------------------------------
 | Mix Asset Management
 |--------------------------------------------------------------------------
 |
 | Mix provides a clean, fluent API for defining some Webpack build steps
 | for your Laravel application. By default, we are compiling the Sass
 | file for the application as well as bundling up all the JS files.
 |
 */

glob.sync('resources/images/**/!(favicon.ico)').map(
    file => mix.copy(file, 'public/images')
);

mix.options({ processCssUrls: false })
    .js('resources/js/app.js', 'public/js')
    .sass('resources/sass/app.scss', 'public/css')
    .copy('resources/images/favicon.ico', 'public')
    .vue()
    .version()
    .extract();

/*
 |--------------------------------------------------------------------------
 | Fix CSS-only chunk dependency (webpack 5 + mini-css-extract-plugin 1.x)
 |--------------------------------------------------------------------------
 |
 | When .extract() creates a separate runtime (manifest.js), the CSS
 | extraction also creates an implicit CSS-only chunk. This chunk is
 | loaded via a <link> tag, NOT via a <script> tag, so the webpack
 | runtime never marks it as "installed". The entry-point callback
 | (which contains new Vue(…).$mount) waits for ALL dependency chunks
 | — including the CSS one — and never fires.
 |
 | This plugin runs after webpack emits files and patches manifest.js
 | to pre-install any chunk IDs that app.js depends on but that have
 | no corresponding .js file (i.e. CSS-only chunks).
 |
 */

mix.webpackConfig({
    plugins: [{
        apply(compiler) {
            compiler.hooks.afterEmit.tap('PreInstallCssChunks', () => {
                // compiler.options.output.path is typically 'public/',
                // but JS files live under 'public/js/'.
                const jsDir = path.join(compiler.options.output.path, 'js');

                const manifestPath = path.join(jsDir, 'manifest.js');
                const appPath      = path.join(jsDir, 'app.js');

                if (!fs.existsSync(manifestPath) || !fs.existsSync(appPath)) return;

                const appContent = fs.readFileSync(appPath, 'utf8');

                // Find entry-point dependency array, e.g. .O(void 0,[170,898],…)
                const depMatch = appContent.match(/\.O\(void 0,\[([0-9,]+)\]/);
                if (!depMatch) return;

                const requiredIds = depMatch[1].split(',').map(Number);

                let manifest = fs.readFileSync(manifestPath, 'utf8');

                // Find the installed-chunks object, e.g. var e={929:0,170:0};
                const installedRe = /(var \w=\{)((\d+:\d+,?)+)(\})/;
                const instMatch   = manifest.match(installedRe);
                if (!instMatch) return;

                const installedIds = new Set(
                    instMatch[2].split(',').map(p => parseInt(p.split(':')[0], 10))
                );

                // Any chunk ID required by the entry but not in the runtime
                // AND without a corresponding .js file is a CSS-only chunk.
                const missing = requiredIds.filter(id => {
                    if (installedIds.has(id)) return false;
                    // Check if a real JS file delivers this chunk
                    const jsFiles = fs.readdirSync(jsDir).filter(f => f.endsWith('.js'));
                    return !jsFiles.some(f => {
                        const content = fs.readFileSync(path.join(jsDir, f), 'utf8');
                        // Chunk files start with (self.webpackChunk…).push([[ID],…])
                        const chunkIdRe = /\.push\(\[\[([0-9,]+)\]/;
                        const m = content.match(chunkIdRe);
                        return m && m[1].split(',').map(Number).includes(id);
                    });
                });

                if (!missing.length) return;

                const additions = missing.map(id => `${id}:0`).join(',');
                manifest = manifest.replace(
                    instMatch[0],
                    `${instMatch[1]}${instMatch[2]},${additions}${instMatch[4]}`
                );
                fs.writeFileSync(manifestPath, manifest);

                console.log(
                    `[PreInstallCssChunks] Patched manifest.js — added chunk(s) ${missing.join(', ')} to installed set.`
                );
            });
        }
    }]
});

/*
 |--------------------------------------------------------------------------
 | Leaflet Map Images
 |--------------------------------------------------------------------------
 |
 | Leaflet needs its marker/layer images in TWO locations:
 |
 | 1. public/css/images/  — Leaflet CSS uses relative url(images/...)
 |    references. Since compiled CSS lives at public/css/app.css,
 |    the browser resolves these to public/css/images/.
 |
 | 2. public/images/      — Leaflet JS sets L.Icon.Default.imagePath
 |    to the value of the `image-path` prop ({{ asset('images') }}).
 |    Default markers loaded via JS look in this directory.
 |
 */

mix.copy('node_modules/leaflet/dist/images', 'public/css/images');
mix.copy('node_modules/leaflet/dist/images', 'public/images');
