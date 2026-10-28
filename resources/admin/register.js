/**
 * The Postal provider form, registered on FluentSMTP's own Vue app.
 *
 * Only ever required by fluentsmtp.js once FluentSMTP has published its Vue
 * runtime: `vue` is an external that resolves to window.FluentMail.Vue, and
 * webpack evaluates it when this module is first required, not when the bundle
 * loads.
 */
import Postal from './Modules/Settings/Partials/Providers/Postal.vue';

export default function register(app) {
    // FluentSMTP's ConnectionWizard renders <component :is="connection.provider">,
    // which falls back to app-level components once its own map has no match.
    if (!app.component('postal')) {
        app.component('postal', Postal);
    }
}
