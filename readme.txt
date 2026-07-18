=== Postal API Connector for FluentSMTP ===
Contributors: onedogsolutions
Tags: fluentsmtp, postal, smtp, email, api
Requires at least: 5.5
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
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

== Frequently Asked Questions ==

= Does this replace FluentSMTP? =

No. It extends FluentSMTP with a Postal-over-API delivery option and requires
FluentSMTP to be active.

= Where do I get the API key? =

In your Postal server: Server → Credentials → New Credential → type "API".

= My mail is rejected as an unauthenticated From address =

The sending domain must be verified on your Postal server. Add and verify the
domain in Postal, then send from an address on that domain.

== Changelog ==

= 1.0.0 =
* Initial release: Postal HTTP API delivery (raw send), setup screen, connection
  test, and encrypted API-key storage.
