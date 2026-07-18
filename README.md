# Postal API Connector for FluentSMTP

A companion WordPress plugin that adds a **Postal** connection to
[FluentSMTP](https://fluentsmtp.com), delivering WordPress mail through a
self-hosted [Postal](https://postalserver.io) server's HTTP API
(`POST /api/v1/send/raw`) instead of SMTP.

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
- The **FluentSMTP** plugin, installed and active
- A **Postal** server with an API credential (Server → Credentials → New
  Credential → type **API**) and a verified sending domain

## Installation

1. Install and activate **FluentSMTP**.
2. Install and activate this plugin.
3. Go to **Settings → Postal (FluentSMTP)** and add a connection:
   - **Postal Server URL** — e.g. `https://postal.example.com`
   - **Server API Key** — the API credential from your Postal server
   - **From Email / Name** — a sender on a domain verified in Postal
4. Click **Test Connection**, then **Save**. Use **Send Test** to send a real
   email through Postal.

Saved connections appear in FluentSMTP and are used automatically for matching
`From` addresses, including FluentSMTP's email logging, fallback connections and
resend-from-log.

### Storing the API key in wp-config.php (optional)

Instead of the database, choose "Store API Keys in Config File" and add:

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

The admin setup screen is a Vue 2 + Element UI app built with Laravel Mix, using
the same stack and component conventions as FluentSMTP so the provider form can
be merged upstream.

```bash
npm install
npm run prod      # build assets/ (committed)
npm run watch     # develop
composer install  # dev-only: PHP lint / phpcs
```

The compiled bundle in `assets/` is committed so the plugin runs on install.

## Upstream merge

This connector is structured so FluentSMTP's maintainers can adopt Postal as a
first-class provider with mostly file moves. See [`merge/README.md`](merge/README.md).

## License

GPL-2.0-or-later.
