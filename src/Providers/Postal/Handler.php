<?php

namespace FluentMail\App\Services\Mailer\Providers\Postal;

use FluentMail\Includes\Support\Arr;
use FluentMail\App\Services\Mailer\BaseHandler;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Postal HTTP API mailer for FluentSMTP.
 *
 * Delivers mail through a self-hosted Postal server's legacy send API
 * (POST /api/v1/send/raw) instead of SMTP. The full MIME message that PHPMailer
 * has already assembled is base64-encoded and handed to Postal, so multipart
 * bodies, inline images, attachments and custom headers survive byte-for-byte —
 * exactly what SMTP would have carried, but over ordinary HTTPS.
 *
 * This class deliberately lives in FluentSMTP's own namespace and mirrors the
 * structure of the bundled providers (see Providers/Postmark/Handler.php) so it
 * can be merged into fluent-smtp upstream without modification.
 *
 * Postal API contract notes:
 *   - Auth is the X-Server-API-Key header (a server credential of type "API").
 *   - The HTTP status is ALWAYS 200. The real outcome lives in the JSON body's
 *     "status" field (success | error | parameter-error). A naive 2xx check
 *     would report success on every failure, so the body must be parsed.
 */
class Handler extends BaseHandler
{
    use ValidatorTrait;

    /**
     * Human-readable messages for Postal's error codes. Mirrors
     * LegacyAPI::SendController::ERROR_MESSAGES on the Postal server so the
     * FluentSMTP email log shows an actionable message rather than a raw code.
     *
     * @var array<string,string>
     */
    protected $errorMessages = [];

    public function __construct($app = null, $manager = null)
    {
        parent::__construct($app, $manager);

        $this->errorMessages = [
            'NoRecipients'                => __('There are no recipients defined to receive this message.', 'fluentsmtp-postal'),
            'NoContent'                   => __('There is no content defined for this email.', 'fluentsmtp-postal'),
            'TooManyToAddresses'          => __('The maximum number of To addresses has been reached (maximum 50).', 'fluentsmtp-postal'),
            'TooManyCCAddresses'          => __('The maximum number of CC addresses has been reached (maximum 50).', 'fluentsmtp-postal'),
            'TooManyBCCAddresses'         => __('The maximum number of BCC addresses has been reached (maximum 50).', 'fluentsmtp-postal'),
            'FromAddressMissing'          => __('The From address is missing and is required.', 'fluentsmtp-postal'),
            'UnauthenticatedFromAddress'  => __('The From address is not authorised to send mail from this Postal server. Verify the domain in Postal.', 'fluentsmtp-postal'),
            'AttachmentMissingName'       => __('An attachment is missing a name.', 'fluentsmtp-postal'),
            'AttachmentMissingData'       => __('An attachment is missing data.', 'fluentsmtp-postal'),
            'AccessDenied'                => __('Access denied. The API key must be a Postal server credential of type "API".', 'fluentsmtp-postal'),
            'InvalidServerAPIKey'         => __('The Postal server API key is not valid.', 'fluentsmtp-postal'),
            'ServerSuspended'             => __('This Postal server has been suspended.', 'fluentsmtp-postal'),
        ];
    }

    public function send()
    {
        if ($this->preSend()) {
            $this->applyForcedFrom();

            if ($this->phpMailer->preSend()) {
                return $this->postSend();
            }
        }

        return $this->handleResponse(new \WP_Error(422, __('Something went wrong while preparing the message.', 'fluentsmtp-postal'), []));
    }

    /**
     * Honor FluentSMTP's "Force From Email" for the raw MIME message. When
     * enabled (the default), the From address is rewritten to the connection's
     * sender_email so mail always leaves on a Postal-verified domain. This runs
     * after BaseHandler::preSend() (which sets FromName/Sender) and before the
     * PHPMailer builds the MIME, so the change lands in the message headers.
     */
    protected function applyForcedFrom()
    {
        if (!$this->isForcedEmail()) {
            return;
        }

        $email = $this->getSetting('sender_email');
        if ($email && is_email($email)) {
            $this->phpMailer->From = $email;

            if ($this->getSetting('return_path') === 'yes') {
                $this->phpMailer->Sender = $email;
            }
        }
    }

    public function postSend()
    {
        $rawMessage = $this->phpMailer->getSentMIMEMessage();

        $body = [
            'mail_from' => $this->getMailFrom(),
            'rcpt_to'   => $this->getEnvelopeRecipients(),
            'data'      => base64_encode($rawMessage),
        ];

        if ($this->getSetting('bounce') === 'yes') {
            $body['bounce'] = true;
        }

        $params = array_merge($this->getDefaultParams(), [
            'headers'   => $this->getRequestHeaders(),
            'body'      => wp_json_encode($body),
            'sslverify' => $this->getSetting('verify_ssl', 'yes') !== 'no',
        ]);

        $response = wp_remote_post($this->getApiUrl('send/raw'), $params);

        $this->response = $this->parsePostalResponse($response);

        return $this->handleResponse($this->response);
    }

    /**
     * Turn Postal's response envelope into either a success array (logged as
     * "sent" with the Postal message id) or a WP_Error (logged as "failed",
     * which also triggers FluentSMTP's fallback-connection retry).
     *
     * @param array|\WP_Error $response Result from wp_remote_post().
     * @return array|\WP_Error
     */
    protected function parsePostalResponse($response)
    {
        // Transport-level failure: DNS, connection refused, timeout, TLS. These
        // are exactly the failures a misconfigured server URL or certificate
        // produce, so surface them verbatim.
        if (is_wp_error($response)) {
            return new \WP_Error(
                $response->get_error_code(),
                sprintf(
                    /* translators: %s: underlying transport error message */
                    __('Could not reach the Postal server: %s', 'fluentsmtp-postal'),
                    $response->get_error_message()
                ),
                $response->get_error_messages()
            );
        }

        $httpCode = (int) wp_remote_retrieve_response_code($response);
        $rawBody  = wp_remote_retrieve_body($response);
        $decoded  = json_decode($rawBody, true);

        // Postal always answers 200 with a JSON envelope. Anything else means we
        // did not actually reach the Postal API (wrong URL, a reverse proxy
        // error page, etc.).
        if (!is_array($decoded) || !isset($decoded['status'])) {
            $snippet = trim(wp_strip_all_tags((string) $rawBody));
            if (strlen($snippet) > 200) {
                $snippet = substr($snippet, 0, 200) . '…';
            }

            return new \WP_Error(
                $httpCode ?: 502,
                sprintf(
                    /* translators: 1: HTTP status code, 2: response snippet */
                    __('Unexpected response from the Postal API (HTTP %1$d). Check the server URL points at your Postal host. Response: %2$s', 'fluentsmtp-postal'),
                    $httpCode,
                    $snippet
                ),
                ['body' => $rawBody]
            );
        }

        $status = $decoded['status'];
        $data   = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [];

        if ($status === 'success') {
            return [
                'id'      => Arr::get($data, 'message_id'),
                'message' => __('Message accepted by Postal.', 'fluentsmtp-postal'),
                'data'    => $data,
            ];
        }

        if ($status === 'parameter-error') {
            return new \WP_Error(
                'parameter-error',
                Arr::get($data, 'message', __('Postal rejected the request parameters.', 'fluentsmtp-postal')),
                $data
            );
        }

        // status === 'error' (or anything else non-success).
        $code    = Arr::get($data, 'code', 'PostalError');
        $message = isset($this->errorMessages[$code])
            ? $this->errorMessages[$code]
            : Arr::get($data, 'message', $code);

        return new \WP_Error($code, $message, $data);
    }

    /**
     * The SMTP envelope sender (Return-Path). Falls back to the From address.
     *
     * @return string
     */
    protected function getMailFrom()
    {
        if (!empty($this->phpMailer->Sender)) {
            return $this->phpMailer->Sender;
        }

        return $this->phpMailer->From;
    }

    /**
     * All envelope recipients (To + Cc + Bcc), de-duplicated. Bcc addresses are
     * present here but not in the MIME data, exactly as with SMTP.
     *
     * @return array<int,string>
     */
    protected function getEnvelopeRecipients()
    {
        $recipients = [];

        foreach (['getToAddresses', 'getCcAddresses', 'getBccAddresses'] as $method) {
            foreach ((array) $this->phpMailer->{$method}() as $address) {
                if (!empty($address[0])) {
                    $recipients[strtolower($address[0])] = $address[0];
                }
            }
        }

        return array_values($recipients);
    }

    protected function getRequestHeaders()
    {
        return [
            'Accept'           => 'application/json',
            'Content-Type'     => 'application/json',
            'X-Server-API-Key' => $this->getApiKey(),
        ];
    }

    /**
     * Build a Postal endpoint URL from the configured server URL.
     *
     * @param string $path e.g. "send/raw" or "messages/message".
     * @return string
     */
    public function getApiUrl($path)
    {
        $base = untrailingslashit(trim((string) $this->getSetting('server_url')));

        return $base . '/api/v1/' . ltrim($path, '/');
    }

    /**
     * Resolve the usable (plaintext) API key regardless of key_store.
     *
     * @return string
     */
    public function getApiKey()
    {
        if ($this->getSetting('key_store') === 'wp_config') {
            return defined('FLUENTSMTP_POSTAL_API_KEY') ? FLUENTSMTP_POSTAL_API_KEY : '';
        }

        return (string) $this->getSetting('api_key');
    }

    /**
     * FluentSMTP hands us the stored provider_settings. The API key is stored
     * encrypted by this plugin (FluentSMTP's own encryption map does not cover
     * the "postal" provider), so decrypt it once here. Decryption is a no-op on
     * a plaintext value, so this is also safe for the live "test connection"
     * path which passes an un-stored key.
     *
     * @param array $settings
     * @return $this
     */
    public function setSettings($settings)
    {
        if (Arr::get($settings, 'key_store') === 'wp_config') {
            $settings['api_key'] = defined('FLUENTSMTP_POSTAL_API_KEY') ? FLUENTSMTP_POSTAL_API_KEY : '';
        } elseif (!empty($settings['api_key'])) {
            $settings['api_key'] = \FluentSmtpPostal\Support\Crypto::decrypt($settings['api_key']);
        }

        $this->settings = $settings;

        return $this;
    }
}
