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

## Status against FluentSMTP 2.4

All three files are drop-in for FluentSMTP 2.4 (Vue 3 + Element Plus). The Vue
partial already follows core's provider conventions (`modelValue`, `#content`
slots, `el-radio-button value=`, `true-value`/`false-value`, a `watch` on
`connection.key_store` that clears `api_key`), and imports `InputPassword` and
`Error` from `@/Pieces`, which resolve to core's own pieces on merge.

Two things in it exist only because it runs as an add-on, and can be deleted
when it moves into core:

- the explicit `import { ElInput, … } from 'element-plus'` and the matching
  `components` entries - core's build auto-imports Element Plus;
- the `<el-config-provider :z-index="100000">` wrapper - core's app sets that
  z-index globally.

Until the merge, the companion plugin registers this component on FluentSMTP's
app at runtime (`resources/admin/fluentsmtp.js`) and answers Postal saves on
core's `settings` action (`src/Admin/SaveInterceptor.php`), because core's
`Settings::store()` reads the title from the static provider catalog.

### The encryption hand-over needs a migration

With `'postal' => 'api_key'` in core's encryption maps, core encrypts and
decrypts the key itself. Connections saved by the companion plugin hold
`postalenc::…` values encrypted with the plugin's own scheme, which core will
not decrypt. Ship a migration in the same release as the merge: detect the
`postalenc::` prefix, decrypt with the plugin's `Crypto`, and re-save through
core's setter so the value is re-encrypted under core's scheme (once, recorded
by an option flag). Verify a legacy connection still sends and that the value is
not double-encrypted.

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
   plugin's `Support/Crypto.php` usage from the handler's `setSettings()`, and
   ship the encryption hand-over migration described above.
4. **`resources/admin/Modules/Settings/ConnectionWizard.vue`** — import and
   register the `Postal` component. See `connection-wizard.entry.js`.

After these edits, `npm run prod` in the FluentSMTP repo rebuilds the admin app
with Postal as a first-class provider, and the companion plugin's integration
layer (`src/Admin/*`, `src/Support/ConnectionStore.php`,
`resources/admin/fluentsmtp.js`, `resources/admin/register.js`) is no longer
needed.

## Why this provider exists

Self-hosted Postal servers commonly sit behind a reverse proxy (e.g. Caddy) with
a valid Let's Encrypt certificate on 443, while the SMTP submission ports
(465/587) have historically been harder to secure — older Postal versions have
STARTTLS/certificate issues on those ports. Delivering over Postal's HTTP API
(`POST /api/v1/send/raw`) uses the already-valid HTTPS endpoint and a scoped,
revocable server API key, sidestepping the SMTP TLS problems entirely.
