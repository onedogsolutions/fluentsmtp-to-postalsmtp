/**
 * Puts the Postal form inside FluentSMTP's connection screen.
 *
 * Printed after FluentSMTP's boot.js and before its app bundle, so at this point
 * window.FluentMail exists but its Vue runtime and app do not. FluentSMTP 2.4's
 * start.js assigns window.FluentMail.Vue, creates the app, assigns
 * window.FluentMail.app and only then mounts it. A setter on `app` therefore
 * runs between creation and mount: the one moment a global component can be
 * added before anything renders, including a page opened directly on a Postal
 * connection's edit route.
 *
 * Deliberately free of imports: the component module, and through it the `vue`
 * external, is required only once FluentSMTP's Vue is there to resolve to.
 * Against FluentSMTP before 2.4 (Vue 2, no `.app`) the setter never fires and
 * nothing happens.
 */
(function () {
    var FluentMail = window.FluentMail;

    if (!FluentMail) {
        return;
    }

    var registered = false;

    function register(app) {
        var Vue = FluentMail.Vue;

        if (registered || !app || typeof app.component !== 'function' || !Vue || typeof Vue.createApp !== 'function') {
            return;
        }

        registered = true;

        try {
            require('./register').default(app);
        } catch (e) {
            // A broken Postal form must not take FluentSMTP's screen down with it.
            window.console && window.console.error('[Postal for FluentSMTP]', e);
        }
    }

    if (FluentMail.app) {
        register(FluentMail.app);
        return;
    }

    var descriptor = Object.getOwnPropertyDescriptor(FluentMail, 'app');

    if (descriptor && !descriptor.configurable) {
        return;
    }

    Object.defineProperty(FluentMail, 'app', {
        configurable: true,
        enumerable: true,
        get: function () {
            return undefined;
        },
        set: function (app) {
            // Back to a plain property, so FluentSMTP and anyone else see what they set.
            Object.defineProperty(FluentMail, 'app', {
                configurable: true,
                enumerable: true,
                writable: true,
                value: app
            });

            register(app);
        }
    });
})();
