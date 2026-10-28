=== Postal API Connector for FluentSMTP ===
Contributors: onedogsolutions
Tags: fluentsmtp, postal, smtp, email, api
Requires at least: 5.5
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a Postal connection to FluentSMTP that delivers mail over the Postal HTTP API instead of SMTP, avoiding SMTP TLS issues on self-hosted Postal servers.

== Description ==

This is a companion plugin for [FluentSMTP](https://fluentsmtp.com). It registers
an additional **Postal** provider so WordPress mail can be delivered through a
self-hosted [Postal](https://postalserver.io) server's HTTP API
(POST /api/v1/send/raw) rather than SMTP.

Self-hosted Postal servers usually sit behind a reverse proxy (Caddy, nginx) with
a valid Let's Encrypt certificate on port 443, while the SMTP submission ports
(465/587) have historically been harder to secure. This connector sends over the
already-valid HTTPS endpoint using a scoped, revocable server API key.

The fully-assembled MIME message is delivered via the raw send endpoint, so
multipart bodies, attachments, inline images and custom headers are transmitted
exactly as SMTP would carry them. FluentSMTP's email logging, fallback
connections and resend-from-log all continue to work.

= Requires =

* The FluentSMTP plugin, installed and active
* A Postal server with an API-type credential and a verified sending domain

== Installation ==

1. Install and activate FluentSMTP.
2. Install and activate this plugin.
3. Go to Settings → Postal (FluentSMTP) and add a connection (server URL, API key, sender).
4. Test the connection and save.

= Why is the Postal form not inside FluentSMTP? =

Postal has no SMTP username or password: it authenticates with a single server
API key, sent as an X-Server-API-Key header. A Postal connection is therefore a
server URL plus an API key, and nothing else.

FluentSMTP 2.4.x draws each provider's fields from a Vue component compiled into
its own bundle, so a separately distributed provider cannot render a form there.
Postal connections are created, tested and edited at Settings → Postal
(FluentSMTP) instead. FluentSMTP's connection wizard shows a note pointing to
that screen, and its Connections screen shows a dismissible notice doing the
same.

Postal connections still appear in FluentSMTP's connection list with their logo
and connection details, and are used for matching From addresses exactly like a
built-in provider. Native fields inside FluentSMTP require merging the provider
upstream; see the repository's merge/ directory.

== Frequently Asked Questions ==

= Does this replace FluentSMTP? =

No. It extends FluentSMTP with a Postal-over-API delivery option and requires
FluentSMTP to be active.

= Where do I get the API key? =

In your Postal server: Server → Credentials → New Credential → type "API".

= My mail is rejected as an unauthenticated From address =

The sending domain must be verified on your Postal server. Add and verify the
domain in Postal, then send from an address on that domain.

= What happens to my Postal connections if I deactivate this plugin? =

They are set aside rather than broken. Deactivating removes them from
FluentSMTP's active connections (so nothing is routed to a provider that is no
longer registered) and keeps them, with their mappings, in the
`fluentsmtp_postal_parked_connections` option. Activating the plugin again puts
them back, and a notice reports how many were restored. A connection is skipped
on restore only if its From address has meanwhile been taken by another
connection.

== Changelog ==

= 1.0.2 =
* Fixed: the Postal settings screen rendered blank. The Vue app read its
  localized variables inside component `data()`, before Vue had initialised
  them, so mounting threw and no form appeared.
* Fixed: `npm run prod` failed on a clean checkout because laravel-mix 6 cannot
  load `webpack/lib/SizeFormatHelpers`. webpack is now pinned to 5.88.2.
* The build no longer fails on CI over a desktop notification error.
* The packaging step now refuses to build a zip whose admin bundle is missing
  the `Postal Server URL` and `Server API Key` fields.

= 1.0.1 =
* FluentSMTP's provider picker now lists Postal with its logo, and the wizard
  shows a note explaining that Postal uses a server URL and API key (no
  username or password) with a link to the Postal settings screen.
* Added a dismissible notice on FluentSMTP's settings screen and a Settings link
  on the Plugins screen, both pointing at Settings → Postal (FluentSMTP).
* Postal connections now show real connection details (server URL, credential
  source, TLS verification, senders) in FluentSMTP instead of the generic
  provider table. The API key itself is never displayed.
* Fixed: saving a Postal connection no longer turns FluentSMTP's email logging
  on or resets its logging interval and connection preferences.
* Fixed: deactivating the plugin no longer breaks site email with an "Unknown
  mail provider" error when a Postal connection exists. Postal connections are
  parked on deactivation and restored on activation.

= 1.0.0 =
* Initial release: Postal HTTP API delivery (raw send), setup screen, connection
  test, and encrypted API-key storage.
