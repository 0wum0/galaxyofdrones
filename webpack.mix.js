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
 | Leaflet CSS references images/layers.png, images/layers-2x.png,
 | images/marker-icon.png etc. relative to the CSS file location.
 | Since our compiled CSS lives at public/css/app.css, these images
 | must be placed at public/css/images/.
 |
 */

mix.copy('node_modules/leaflet/dist/images', 'public/css/images');
