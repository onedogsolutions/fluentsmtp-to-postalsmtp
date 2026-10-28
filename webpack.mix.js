const path = require('path');
const mix = require('laravel-mix');

mix.options({ processCssUrls: false });

// Desktop notifications make the build exit non-zero on macOS and CI machines
// that cannot spawn the notifier, even though compilation succeeded.
mix.disableNotifications();

mix.setPublicPath('assets');
mix.setResourceRoot('../');

mix.alias({
    '@': path.join(__dirname, 'resources/admin')
});

mix.webpackConfig({
    // The form runs inside FluentSMTP's app, so it must use FluentSMTP's Vue: a
    // second runtime would have its own reactivity and its own component tree.
    // Element Plus is bundled, and its own `import 'vue'` resolves here as well.
    externals: {
        vue: 'FluentMail.Vue'
    }
});

mix
    .js('resources/admin/fluentsmtp.js', 'assets/admin/js/postal-fluentsmtp.js').vue({ version: 3 })
    .copy('resources/images', 'assets/images');
