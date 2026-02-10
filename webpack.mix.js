const mix = require('laravel-mix');
const glob = require('glob');

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
