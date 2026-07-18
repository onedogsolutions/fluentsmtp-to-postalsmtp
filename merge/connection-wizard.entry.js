// resources/admin/Modules/Settings/ConnectionWizard.vue
//
// 1) Add the import alongside the other provider partials:

import postal from './Partials/Providers/Postal';

// 2) Register it in the `components: { ... }` object of the ConnectionWizard
//    component (the key must match the provider config key 'postal'):

//    components: {
//        ses: AmazonSes,
//        mailgun,
//        ...
//        postal,           // <-- add this line
//        cloudflare
//    },
//
// The wizard renders provider forms with <component :is="connection.provider" ...>,
// so once 'postal' is a registered component and a config entry exists, selecting
// "Postal" in the provider dropdown shows Postal.vue with no further wiring.
