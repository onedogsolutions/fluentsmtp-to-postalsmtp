# Postal API Connector for FluentSMTP

A companion WordPress plugin that adds a **Postal** connection to
[FluentSMTP](https://fluentsmtp.com), delivering WordPress mail through a
self-hosted [Postal](https://postalserver.io) server's HTTP API
(`POST /api/v1/send/raw`) instead of SMTP.

> 📣 **Shoutout to Jewel at WPManageNinja!** 👋 We'd love to see Postal
> supported natively in FluentSMTP — please consider merging this into core
> **ASAP**. It was built specifically to mirror FluentSMTP's own structure, so
> adopting it is mostly moving files: the drop-in provider `Handler`,
> `ValidatorTrait`, and `Postal.vue` are ready to go. See
> [`merge/README.md`](merge/README.md) for the exact steps. Thanks, friend! 🙏

## Why

Self-hosted Postal servers usually sit behind a reverse proxy (Caddy, nginx)
with a valid Let's Encrypt certificate on port 443, while the SMTP submission
ports (465/587) have historically been harder to secure — older Postal
versions have STARTTLS/certificate problems there. This connector sends over
the already-valid HTTPS API endpoint using a scoped, revocable **server API
key**, so it sidesteps the SMTP TLS stack entirely while keeping delivery on
your own infrastructure.

Because PHPMailer's fully-assembled MIME message is delivered via
`/api/v1/send/raw`, multipart bodies, inline images, attachments, custom
headers and DKIM-relevant structure are transmitted byte-for-byte — exactly
what SMTP would have carried.

## Requirements

- WordPress 5.5+
- PHP 7.4+
- The **FluentSMTP** plugin, installed and active — **2.4 or later** to add or
  edit Postal connections (existing ones keep sending on older versions)
- A **Postal** server with an API credential (Server → Credentials → New
  Credential → type **API**) and a verified sending domain

## Installation

1. Install and activate **FluentSMTP**.
2. Install and activate this plugin.
3. Go to **FluentSMTP → Connections → Add Connection** (the plugin's
   **Settings** link on the Plugins screen goes there too) and pick **Postal**:
   - **From Email / Name** — a sender on a domain verified in Postal
   - **Postal Server URL** — e.g. `https://postal.example.com`
   - **Server API Key** — the API credential from your Postal server
4. Click **Save Connection Settings**. The connection is checked against your
   Postal server before it is saved. Use FluentSMTP's **Email Test** screen to
   send a real email through it.

Postal connections are edited and deleted from FluentSMTP's connection list like
any other provider, and are used automatically for matching `From` addresses,
including FluentSMTP's email logging, fallback connections and resend-from-log.

### Postal has no username or password

Postal authenticates a delivery with a single **server API key**, sent as the
`X-Server-API-Key` header, so a Postal connection is a **server URL + API key**
— nothing else. There is no SMTP username, password, port or encryption setting
to enter.

### How the form gets into FluentSMTP

FluentSMTP has no API for third-party providers, so this plugin stitches Postal
into its admin app without modifying it:

- The Postal provider descriptor (title, logo, default options) is merged into
  the providers map FluentSMTP's app reads, which puts Postal in the picker,
  the connection list and the details view.
- `assets/admin/js/postal-fluentsmtp.js` registers the Postal form as a global
  `postal` component on FluentSMTP's own Vue app, just before it mounts.
  FluentSMTP's wizard renders provider fields through
  `<component :is="connection.provider">`, so the form appears in its slot. The
  bundle uses FluentSMTP's Vue runtime (`window.FluentMail.Vue`) rather than
  shipping its own.
- FluentSMTP's own save endpoint cannot store a provider missing from its
  built-in catalog, so Postal saves posted to it are answered by this plugin
  (validation, live connection check, encrypted storage) in the same response
  format. Every other provider's save goes to FluentSMTP unchanged.

These rely on FluentSMTP 2.4 internals. The plugin checks for them and does
nothing when they are missing; merging the provider upstream (below) removes
the need for any of it.

### Storing the API key in wp-config.php (optional)

Instead of the database, choose "Store in wp-config.php" and add:

```php
define( 'FLUENTSMTP_POSTAL_API_KEY', 'your-postal-api-key' );
```

When stored in the database, the key is encrypted at rest using your WordPress
security keys (requires the OpenSSL PHP extension).

## Security notes

- Delivery is over HTTPS to the URL you configure; the API key is sent only in
  the `X-Server-API-Key` header.
- **Verify SSL Certificate** is on by default. Only disable it for self-signed
  certificates on isolated lab servers — the connection stays encrypted but is
  no longer authenticated.
- The sending domain must be verified on your Postal server, otherwise Postal
  rejects the message as an unauthenticated From address.

## Development

The Postal form is a Vue 3 + Element Plus component built with Laravel Mix,
written against FluentSMTP 2.4's own conventions (`$t`, `Pieces/InputPassword`,
`Pieces/Error`) so it can be merged upstream unchanged. `vue` is a webpack
external resolving to `FluentMail.Vue`; Element Plus components are imported
individually and their CSS comes from FluentSMTP.

```bash
npm install
npm run prod      # build assets/ (committed)
npm run watch     # develop
composer install  # dev-only: PHP lint / phpcs
```

The compiled bundle in `assets/` is committed so the plugin runs on install.

## Packaging a release

```bash
./bin/package.sh
```

This writes `dist/fluentsmtp-postal-<version>.zip`, ready for
**Plugins → Add New → Upload Plugin** (or `wp plugin install`). The version comes
from the plugin header in `fluentsmtp-postal.php`, so bump it there — and
`Stable tag` in `readme.txt` — before tagging a release.

The archive contains a single top-level `fluentsmtp-postal/` directory with only
what runs at runtime: the PHP sources, the compiled `assets/` bundle, `readme.txt`
and `LICENSE`. Source files under `resources/`, the `merge/` upstream kit, npm and
webpack configs, PHP dev tooling and CI config are excluded.

After building, the script boots the staged copy in isolation
([`bin/smoke-test.php`](bin/smoke-test.php)) to confirm the plugin's autoloader,
classes and asset paths resolve — both with and without FluentSMTP present — and
fails the build otherwise.

## Upstream merge

This connector is structured so FluentSMTP's maintainers can adopt Postal as a
first-class provider with mostly file moves. See [`merge/README.md`](merge/README.md).

The provider partial is already Vue 3 + Element Plus, matching FluentSMTP 2.4.

## License

GPL-2.0-or-later.
