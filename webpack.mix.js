const path = require('path');
const mix = require('laravel-mix');

mix.options({ processCssUrls: false });

mix.setPublicPath('assets');
mix.setResourceRoot('../');

mix.alias({
    '@': path.join(__dirname, 'resources/admin')
});

mix
    .js('resources/admin/main.js', 'assets/admin/js/postal-admin.js').vue({ version: 2 })
    .sass('resources/scss/postal-admin.scss', 'assets/admin/css/postal-admin.css')
    .copy('node_modules/element-ui/lib/theme-chalk/fonts', 'assets/admin/css/fonts')
    .copy('resources/images', 'assets/images');
