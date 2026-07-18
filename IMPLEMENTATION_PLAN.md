# Implementation Plan: Postal API Connector for FluentSMTP

A companion WordPress plugin that adds a **Postal** connection type to
[FluentSMTP](https://github.com/WPManageNinja/fluent-smtp), delivering mail to a
self-hosted [Postal](https://github.com/postalserver/postal) server over its
HTTPS API instead of SMTP.

**Why:** Older Postal releases have SMTP TLS problems (STARTTLS negotiation
failures, self-signed/expired cert handling), and SMTP credentials transit the
network on every send. Postal's HTTP API (`/api/v1/send/*`) runs over ordinary
HTTPS — typically terminated by a reverse proxy with a valid certificate — so
routing WordPress mail through the API sidesteps the SMTP TLS stack entirely
and authenticates with a scoped, revocable server API key.

Research below was done against **fluent-smtp v2.2.95** (`5346b2a`, 2026-06-14)
and **postal** `d038eaa` (2026-06-03). File references are to those trees.

---

## 1. Research findings

### 1.1 How FluentSMTP resolves and runs a mailer

The send path never consults the static provider catalog — it only needs
(a) a connection entry in the settings option and (b) a container binding
for the provider key:

1. FluentSMTP replaces `wp_mail()`; `fluentMailSend()` builds a PHPMailer
   instance and hands it to `FluentPHPMailer::send()`
   (`app/Services/Mailer/FluentPHPMailer.php`).
2. `fluentMailGetProvider($fromEmail)` (`app/Functions/helpers.php:133`) looks
   up `settings.mappings[$fromEmail]` → `settings.connections[$id]['provider_settings']`,
   then calls `Factory::make($connection['provider'])`
   (`app/Services/Mailer/Providers/Factory.php`) — which is just
   `$app->make('postal')` against FluentSMTP's IoC container.
3. The resolved handler gets `setSettings($connection)` + `setPhpMailer($pm)`
   and its `send()` is called. Logging, retries, fallback connections, and the
   email-log "resend" feature all live in `BaseHandler`
   (`app/Services/Mailer/BaseHandler.php`), so any handler extending it
   inherits them for free.

**Extension points that exist:**

| Hook | Where | Use |
|---|---|---|
| `do_action('fluentMail_loaded', $app)` | `fluent-smtp.php:41` | Receives the IoC container at boot — register our handler binding here |
| `do_action('fluent_mail_loading_app')` | `AdminMenuHandler.php:219` | Fires after `FluentMailAdmin` JS object is localized, before the Vue bundle — inject display metadata for the admin UI |
| `apply_filters('fluentmail_saving_connection_data', $data, $provider)` | `SettingsController.php:89` | Intercept core connection saves if ever needed |

**Extension points that do NOT exist (and shape the design):**

- The provider catalog (`app/Services/Mailer/Providers/config.php`) and the
  container bindings (`app/Bindings.php`) are static arrays with **no filter**.
- The admin UI is a compiled Vue app. `ConnectionWizard.vue` statically imports
  one form component per provider and renders `<component :is="connection.provider">`,
  so a third-party provider key **cannot get a native settings form** inside
  FluentSMTP's wizard without an upstream PR.
- The connections list (`Connections.vue`) guards with
  `v-if="settings.providers[scope.row.provider]"` and optional chaining, so a
  foreign connection renders safely (title from the stored connection, no logo)
  — it degrades gracefully, it doesn't crash.
- Secret-at-rest encryption (`fluentMailGetSettings`/`fluentMailSetSettings`,
  `app/Functions/helpers.php:663-790`) uses a **hard-coded provider→field map**
  (`'postmark' => 'api_key'`, …). A `postal` provider key is skipped, so
  FluentSMTP will neither encrypt nor mangle our API key — the companion plugin
  must encrypt/decrypt it itself.
- Core `Settings::store()` (`app/Models/Settings.php`) looks up
  `$providers[$provider]['title']` from the static catalog — it would notice
  an unknown key. Our plugin therefore writes its connections with its own
  storage routine rather than piggybacking on core's store endpoint.
  Importantly, core's store/read paths **preserve unknown connections
  untouched**, so Postal connections coexist safely with core-managed ones.

**Handler contract** (from `BaseHandler` + the Postmark/SendGrid handlers,
which are the closest API-based templates):

- `send()` — call `$this->preSend()` (applies force-from/return-path, builds
  `$this->attributes`) and `$this->phpMailer->preSend()` (builds the MIME
  message), then `postSend()` which does the HTTP call.
- On completion call `$this->handleResponse($arrayOrWpError)` — an array like
  `['id' => …, 'message' => …]` marks success; a `WP_Error` triggers the log
  entry, failure hooks, and fallback-connection retry.
- Optional overrides used by the settings flow: `validateProviderInformation()`
  (field validation), `checkConnection($connection)` (live probe; base returns
  `true`), `getValidSenders($connection)` (base returns `[sender_email]`),
  `getConnectionInfo($connection)` (HTML for the connection-details modal).

### 1.2 Postal's HTTP API contract

From `app/controllers/legacy_api/{base,send}_controller.rb` and
`config/routes.rb` in the postal repo:

- **Auth:** `X-Server-API-Key` header, holding a server credential of type
  `API` (created in Postal under *Server → Credentials → API*).
- **Endpoints** (POST, JSON body with `Content-Type: application/json`):
  - `/api/v1/send/message` — structured send: `to[]` (max 50), `cc[]`, `bcc[]`,
    `from`, `sender`, `subject`, `reply_to`, `plain_body`, `html_body`, `tag`,
    `headers{}`, `attachments[]` of `{name, content_type, data(base64)}`.
  - `/api/v1/send/raw` — SMTP-equivalent send: `mail_from` (envelope sender),
    `rcpt_to[]` (envelope recipients), `data` (base64 RFC 2822 message).
  - `/api/v1/messages/message` — fetch a message by id (useful as an auth probe).
- **Response convention (critical):** HTTP status is **always `200 OK`**. The
  real outcome is in the JSON body: `status` = `success` | `error` |
  `parameter-error`, with details in `data` (error `code`, `message`). A naive
  "2xx = sent" check would report success on every failure, so the handler must
  parse the body.
- **Success payload:** `data.message_id` plus `data.messages` (per-recipient
  `{id, token}`) — `message_id` goes into FluentSMTP's email log.
- **Error codes to map to readable messages:** `AccessDenied`,
  `InvalidServerAPIKey`, `ServerSuspended`, `UnauthenticatedFromAddress` (the
  From domain isn't authorized on that Postal server), `NoRecipients`,
  `NoContent`, `TooManyToAddresses`, `AttachmentMissingName`, etc.

---

## 2. Architecture decision

**Chosen approach — Option A: standalone companion plugin** that

1. registers a `postal` handler into FluentSMTP's container at
   `fluentMail_loaded`,
2. ships its **own** admin page for creating/editing Postal connections, and
3. writes those connections into FluentSMTP's `fluentmail-settings` option in
   the exact shape core expects, so the entire send/log/fallback/resend
   machinery works unchanged.

**Rejected alternatives:**

- *Fork fluent-smtp* — permanent maintenance burden, users lose auto-updates.
- *Upstream PR only (Option B)* — the right long-term home (config entry +
  `Bindings.php` entry + a Vue form component + the encryption-map entry), but
  acceptance and release timing are outside our control. The companion plugin's
  `Handler`/`ValidatorTrait` will mirror core's `Providers/<Name>/` layout so
  an upstream PR later is mostly a copy — Option B stays open as a follow-up.
- *Register a fake "SMTP" connection pointing at Postal* — doesn't avoid the
  SMTP/TLS stack, which is the whole point.

**Default send mode: `raw`** (`/api/v1/send/raw`). PHPMailer has already built
the full MIME message (`getSentMIMEMessage()` after `preSend()`); base64 it
and Postal transmits exactly what SMTP would have carried — multipart bodies,
inline images, attachments, and custom headers survive with zero re-mapping
bugs. A structured `message` mode (`/api/v1/send/message`) is a Phase-2 option
for users who want Postal-native features (per-message `tag`, Postal's own
per-recipient handling); its 50-recipient-per-field limits and body re-mapping
make it the more fragile path, hence not the default.

---

## 3. Plugin design

**Working name:** *Postal Mailer for FluentSMTP* — slug/text-domain
`fluentsmtp-postal`, provider key `postal`, namespace `FluentSmtpPostal\`.
Requires PHP 7.4+ and WordPress 5.5+ (matching FluentSMTP's floor).

### 3.1 Repository layout

```
fluentsmtp-postal.php            # bootstrap: constants, autoload, dependency guard
src/
  Plugin.php                     # wiring: hooks, service registration
  ProviderRegistrar.php          # binds 'postal' into FluentSMTP's container
  Mailer/
    Handler.php                  # extends FluentMail\...\BaseHandler
    ValidatorTrait.php           # field validation + live connection check
  Admin/
    SettingsPage.php             # connection manager UI (server-rendered)
    Ajax.php                     # save/delete/test endpoints (admin-ajax, nonced)
    UiBridge.php                 # JS injection into FluentSMTP's admin app
  Support/
    ConnectionStore.php          # read/write into fluentmail-settings option
    Crypto.php                   # api_key encrypt/decrypt at rest
assets/
  postal-logo.svg
  admin.js                       # FluentMailAdmin providers injection + edit redirect
readme.txt                       # WP.org-style readme
composer.json                    # dev tooling only (phpcs/WPCS, phpstan)
.github/workflows/ci.yml         # lint + phpstan + phpunit
tests/                           # unit + wp-env integration tests
```

No JS build step: the admin page is server-rendered PHP; `admin.js` is a small
vanilla-JS file.

### 3.2 Bootstrap & registration

- `fluentsmtp-postal.php`: define constants, register a PSR-4 autoloader
  (plain `spl_autoload_register`, no runtime composer dependency), then:
  - `add_action('fluentMail_loaded', [ProviderRegistrar, 'register'])` —
    receives the `$app` container; do exactly what core `app/Bindings.php`
    does: `$app->singleton(Handler::class, fn() => new Handler());`
    `$app->alias(Handler::class, 'postal');`
  - If FluentSMTP is missing/inactive (`fluentMail_loaded` never fires /
    `FLUENTMAIL` undefined by `plugins_loaded`), show an admin notice with an
    install link and do nothing else. Also `register_activation_hook` check.
  - Guard all references to FluentSMTP internals with `class_exists()` so a
    FluentSMTP update that moves an internal class degrades to a notice, not a
    fatal.

### 3.3 Connection settings schema

Stored as `provider_settings` in FluentSMTP's option, same shape as core
connections:

```php
[
  'provider'           => 'postal',
  'sender_name'        => '',
  'sender_email'       => '',            // primary From / mapping key
  'force_from_name'    => 'no',
  'force_from_email'   => 'yes',
  'return_path'        => 'yes',
  'server_url'         => '',            // e.g. https://postal.example.com (normalized, no trailing /)
  'api_key'            => '',            // encrypted at rest by our Crypto (see 3.6)
  'key_store'          => 'db',          // 'db' | 'wp_config' (FLUENTSMTP_POSTAL_API_KEY)
  'send_mode'          => 'raw',         // 'raw' | 'message' (message mode = Phase 2)
  'verify_ssl'         => 'yes',         // 'no' allows self-signed HTTPS (warned, see 3.6)
  'additional_senders' => [],            // extra From addresses mapped to this connection
]
```

### 3.4 `Mailer\Handler` (the core deliverable)

Modeled on `Providers/Postmark/Handler.php`:

```php
class Handler extends \FluentMail\App\Services\Mailer\BaseHandler
{
    use ValidatorTrait;

    public function send()
    {
        if ($this->preSend() && $this->phpMailer->preSend()) {
            return $this->postSend();
        }
        return $this->handleResponse(new \WP_Error(422, __('PHPMailer pre-send failed', 'fluentsmtp-postal')));
    }

    public function postSend()
    {
        $body = [
            'mail_from' => $this->phpMailer->Sender ?: $this->phpMailer->From,
            'rcpt_to'   => $this->getEnvelopeRecipients(),   // to + cc + bcc addresses
            'data'      => base64_encode($this->phpMailer->getSentMIMEMessage()),
        ];

        $response = wp_remote_post($this->getApiUrl('send/raw'), [
            'headers'   => [
                'Content-Type'     => 'application/json',
                'X-Server-API-Key' => $this->getApiKey(),
            ],
            'body'      => wp_json_encode($body),
            'timeout'   => $this->getDefaultParams()['timeout'],
            'sslverify' => $this->getSetting('verify_ssl') !== 'no',
        ]);

        $this->response = $this->parsePostalResponse($response);
        return $this->handleResponse($this->response);
    }
}
```

Key behaviors:

- **`parsePostalResponse()`** — transport-level `WP_Error` (DNS, timeout, TLS)
  passes through as-is; otherwise JSON-decode and branch on `status`:
  `success` → `['id' => data.message_id, 'message' => 'OK']`; `error` /
  `parameter-error` → `WP_Error(data.code, mappedMessage, data)`. Ship a
  code→message map mirroring Postal's `SendController::ERROR_MESSAGES` so the
  FluentSMTP email log shows "The From address is not authorised to send mail
  from this server" instead of `UnauthenticatedFromAddress`. Never trust the
  HTTP status code (always 200 — see §1.2).
- **`getEnvelopeRecipients()`** — merge `getToAddresses()`, `getCcAddresses()`,
  `getBccAddresses()` into a unique flat address list. Bcc handling is why raw
  mode must set envelope recipients explicitly: Bcc addresses appear in
  `rcpt_to` but not in the MIME data, exactly like SMTP.
- **`getApiUrl($path)`** — `rtrim(server_url, '/') . '/api/v1/' . $path`.
- **`getApiKey()`** — `key_store === 'wp_config'` → `FLUENTSMTP_POSTAL_API_KEY`
  constant; otherwise decrypt the stored value (see 3.6).
- **`setSettings()`** override — decrypt `api_key` once, mirroring how
  Postmark's override resolves its wp_config key.
- **`getConnectionInfo()`** override — render server URL, sender, send mode,
  and a masked key in FluentSMTP's connection-details modal.

### 3.5 Validation, live check, admin UI

**`ValidatorTrait`** (mirrors `Providers/Postmark/ValidatorTrait.php`):
`validateProviderInformation()` requires `server_url` (valid `https://` URL —
`http://` allowed only behind an explicit "insecure URL" acknowledgement) and
`api_key` (or the wp_config constant when `key_store = wp_config`).

**`checkConnection($connection)`** — a deterministic, non-sending auth probe:
POST `{server}/api/v1/messages/message` with `{"id": 0}`. Postal authenticates
before dispatch, so the response `data.code` tells us exactly what we need:
`MessageNotFound` → URL + key are good; `InvalidServerAPIKey` / `AccessDenied`
→ bad key; transport `WP_Error` → unreachable host / TLS problem. Surface each
as a distinct, actionable error. This runs on save and behind a "Test
Connection" button.

**`Admin\SettingsPage`** — a submenu page under FluentSMTP's menu (fallback:
under *Settings*) listing Postal connections with add/edit/delete, the fields
from §3.3, a *Test Connection* button (auth probe) and *Send Test Email*
button (routes through `wp_mail()` with the connection's sender as From — i.e.
through the real handler path). All actions: `manage_options` + nonce. The
API key field is write-only (masked placeholder when set; blank submit keeps
the existing key).

**`Support\ConnectionStore`** — replicates `Settings::store()` semantics
without the static-catalog title lookup:

- `connection_key = md5(sender_email)` (core's `generateUniqueKey`).
- Write `connections[$key] = ['title' => 'Postal', 'provider_settings' => $data]`.
- Point `mappings[sender_email]` and each `additional_senders` entry at the key
  (skipping emails already claimed as another connection's primary sender, as
  core does).
- Set `misc.default_connection` if empty.
- Persist via `fluentMailSetSettings()` (its encryption loop skips unknown
  provider keys, so our pre-encrypted `api_key` passes through untouched), then
  bust caches: `fluentMailGetSettings([], false)` and
  `fluentMailGetProvider($email, true)`.
- Deletes remove the connection, its mappings, and reassign
  `misc.default_connection` if it pointed at us.

**`Admin\UiBridge`** (cosmetic, Phase 3) — on `fluent_mail_loading_app`,
enqueue `admin.js` (dependent on `fluent_mail_admin_app_boot`, loaded before
the Vue bundle executes) which:

- injects `FluentMailAdmin.settings.providers.postal = {key, title: 'Postal', image: <logo url>}`
  so the connections list shows a Postal logo/title, and
- intercepts *Edit* clicks on Postal rows to redirect to our settings page —
  the compiled wizard has no `postal` form component (§1.1), so editing there
  would show a half-empty form.

### 3.6 Security

- **Key at rest:** encrypt `api_key` with `fluentMailEncryptDecrypt()`
  (AES-256-CTR keyed off WP salts — same primitive core uses) in our own
  `Crypto` wrapper, since FluentSMTP's map won't cover `postal` (§1.1). Fall
  back to plaintext-with-warning only if OpenSSL is unavailable, exactly as
  core behaves.
- **Key in transit/UI:** never echo the stored key into markup; POST-only
  handling; key sent exclusively as the `X-Server-API-Key` header over the
  configured URL.
- **TLS:** `verify_ssl` defaults to `yes`; setting `no` (for self-signed lab
  installs) shows a persistent warning on the settings page. URL scheme
  validation defaults to `https://` (see §3.5). Note in docs: even with
  verification off, the API path still avoids the historic Postal SMTP-side
  TLS negotiation failures, which is the motivating use case.
- **Standard WP hygiene:** capability checks (`manage_options`), nonces on all
  admin/ajax actions, `sanitize_*`/`esc_*` throughout, no dynamic URLs
  fetchable by non-admins (server URL is admin-configured only — no SSRF
  surface beyond what an admin already has).

---

## 4. Delivery phases

**Phase 0 — Scaffolding (≈½ day):** repo layout from §3.1, bootstrap +
dependency guard, CI (phpcs WPCS + phpstan + phpunit skeleton), logo asset.

**Phase 1 — MVP send path (the core milestone):**
`ProviderRegistrar`, `Handler` in raw mode with full Postal response parsing,
`ValidatorTrait` + `checkConnection` probe, minimal settings page (single
connection: server URL, API key, sender email/name, force-from, verify SSL),
`ConnectionStore` write/delete, test-email button.
*Exit criteria:* on a clean WP install with FluentSMTP, configure a Postal
connection and (1) test email arrives, (2) it appears in FluentSMTP's email
log with the Postal `message_id`, (3) a wrong API key / wrong URL / unverified
cert each produce a distinct readable error in log + UI, (4) resend-from-log
works.

**Phase 2 — Completeness:** multiple connections + `additional_senders`
mappings, `key_store = wp_config` support, structured `message` send mode
(+ optional `tag`), key encryption at rest (`Crypto`), interop testing with
FluentSMTP's fallback-connection feature, `getConnectionInfo()` modal.

**Phase 3 — Polish & docs:** `UiBridge` logo/edit-redirect injection, i18n
pass, `readme.txt` + setup guide (creating the API credential in Postal:
*Server → Credentials → New Credential → type API*; domain must be verified on
the Postal server or sends fail with `UnauthenticatedFromAddress`).

**Phase 4 — Hardening & release:** full test matrix (§5), tagged GitHub
release with installable zip via CI, decide on WordPress.org submission
(naming must follow the "… for FluentSMTP" trademark-safe pattern).

**Follow-up (optional):** upstream PR to fluent-smtp adding Postal as a
first-class provider — config + bindings + encryption-map entries and a Vue
form component cloned from `Partials/Providers/PostMark.vue`; our handler code
ports as-is. Companion plugin then defers to the core provider when detected.

---

## 5. Testing plan

- **Unit (PHPUnit + Brain Monkey):** payload construction (envelope recipients
  incl. Bcc; base64 round-trip), response parsing for every Postal `status` /
  error code and transport failures, URL normalization, crypto round-trip,
  `ConnectionStore` output shape vs. a fixture of core's settings structure.
- **Integration (wp-env):** FluentSMTP (latest from WP.org) + this plugin +
  a **mock Postal** mu-plugin exposing `/api/v1/send/raw|message|messages/message`
  with Postal's exact response envelope — asserts what WordPress actually
  POSTs for HTML/plain/multipart mail, attachments, cc/bcc, reply-to,
  force-from, and that FluentSMTP logs success/failure correctly. Runs in CI.
- **E2E (manual, pre-release):** real Postal via its official Docker install;
  verify delivery, DKIM signing of raw messages, `UnauthenticatedFromAddress`
  on an unverified domain, self-signed-cert behavior with `verify_ssl` on/off,
  and a FluentSMTP version-bump smoke test (the internals we touch —
  container, settings shape, `BaseHandler` — are stable across recent
  releases, but they are not a public API; CI should also run against
  FluentSMTP's `master`).
- **Compatibility matrix:** PHP 7.4/8.x, WP 5.5+/latest, FluentSMTP current +
  master; Postal v2 and v3 (the legacy API is identical across both — it's
  Postal's original and still-supported send surface).

## 6. Risks & open questions

| Risk | Mitigation |
|---|---|
| FluentSMTP internals (container, settings shape, `BaseHandler`) change — not a public API | `class_exists` guards + admin notice instead of fatals; CI against FluentSMTP master; upstream PR as the durable fix |
| User edits a Postal connection via FluentSMTP's wizard (no form component) | Phase 3 edit-redirect; docs; connection still renders safely in the list (§1.1) |
| Postal "always 200" responses mask failures | Body-status parsing is the contract (§1.2); unit tests cover every code |
| Very large attachments (Postal's default message size limits) | Surface Postal's error verbatim in the log; document the server-side limit |
| Secret stored by a mechanism core doesn't know about | Self-managed crypto (§3.6) is invisible to core's read/write loops — verified against `helpers.php:663-790` |

Open questions (non-blocking, decide during Phase 1):

1. Menu placement: submenu inside FluentSMTP's menu vs. *Settings* — cosmetic.
2. Whether to also expose Postal's per-message `tag` in raw mode via an
   `X-Postal-Tag`-style custom header, or only in `message` mode.
3. WordPress.org distribution vs. GitHub-releases-only.
