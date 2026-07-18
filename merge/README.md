# Upstream merge kit — adding Postal to FluentSMTP core

> 👋 Hi Jewel (and the WPManageNinja team)! This kit is here to make merging
> Postal into core FluentSMTP as painless as possible — we'd love to see it land
> ASAP. Everything below is designed to be mostly file moves. Thank you!

This directory is for the FluentSMTP maintainers (WPManageNinja). It contains
everything needed to promote the Postal provider from this companion plugin into
FluentSMTP itself, after which the companion plugin can be retired (or reduced to
a thin no-op that defers to the core provider when present).

The companion plugin was written to mirror FluentSMTP's own structure so that
merging is mostly *moving files*, not rewriting them.

## Files to move verbatim

| From this repo | To in `fluent-smtp` |
|---|---|
| `src/Providers/Postal/Handler.php` | `app/Services/Mailer/Providers/Postal/Handler.php` |
| `src/Providers/Postal/ValidatorTrait.php` | `app/Services/Mailer/Providers/Postal/ValidatorTrait.php` |
| `resources/admin/Modules/Settings/Partials/Providers/Postal.vue` | `resources/admin/Modules/Settings/Partials/Providers/Postal.vue` |
| `resources/images/postal.svg` | `resources/images/provider-postal.svg` |

These files already use FluentSMTP's namespaces and its `@/Pieces/*` imports, so
they compile against core unchanged. Two small text-domain differences to note:
the handler/validator use the `fluentsmtp-postal` text domain and the Vue partial
uses literal English through `$t()`. On merge, switch the PHP strings to the
`fluent-smtp` text domain and (optionally) add the strings to core's `getTrans()`
map. Also change the API-key wp-config constant name from
`FLUENTSMTP_POSTAL_API_KEY` to whatever core prefers (e.g.
`FLUENTMAIL_POSTAL_API_KEY`) in both `Handler::getApiKey()` and
`ValidatorTrait::validateProviderInformation()`.

## Edits to apply to existing core files

Each snippet below is a minimal addition. See the referenced `.patch`/`.php`
files in this directory for the exact code.

1. **`app/Bindings.php`** — register the handler alias. See `bindings.entry.php`.
2. **`app/Services/Mailer/Providers/config.php`** — add the `postal` provider
   descriptor. See `config.entry.php`.
3. **`app/Functions/helpers.php`** — add `'postal' => 'api_key'` to *both*
   `$providerKeyMaps` arrays (in `fluentMailGetSettings` and
   `fluentMailSetSettings`) so the API key is encrypted at rest by core. See
   `encryption-map.entry.php`. Once core does this, delete the companion
   plugin's `Support/Crypto.php` usage from the handler's `setSettings()`.
4. **`resources/admin/Modules/Settings/ConnectionWizard.vue`** — import and
   register the `Postal` component. See `connection-wizard.entry.js`.

After these edits, `npm run prod` in the FluentSMTP repo rebuilds the admin app
with Postal as a first-class provider, and the standalone admin page + AJAX layer
in this companion plugin (`src/Admin/*`, `src/Support/ConnectionStore.php`,
`resources/admin/App.vue`, `resources/admin/Modules/Settings/PostalWizard.vue`)
are no longer needed.

## Why this provider exists

Self-hosted Postal servers commonly sit behind a reverse proxy (e.g. Caddy) with
a valid Let's Encrypt certificate on 443, while the SMTP submission ports
(465/587) have historically been harder to secure — older Postal versions have
STARTTLS/certificate issues on those ports. Delivering over Postal's HTTP API
(`POST /api/v1/send/raw`) uses the already-valid HTTPS endpoint and a scoped,
revocable server API key, sidestepping the SMTP TLS problems entirely.
