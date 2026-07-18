<?php

namespace FluentMail\App\Services\Mailer\Providers\Postal;

use FluentMail\Includes\Support\Arr;
use FluentMail\App\Services\Mailer\ValidatorTrait as BaseValidatorTrait;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validation and live connection check for the Postal provider.
 *
 * Mirrors the structure of the bundled providers' ValidatorTrait so it merges
 * cleanly into fluent-smtp upstream.
 */
trait ValidatorTrait
{
    use BaseValidatorTrait;

    public function validateProviderInformation($connection)
    {
        $errors = [];

        $serverUrl = trim((string) Arr::get($connection, 'server_url'));
        if (!$serverUrl) {
            $errors['server_url']['required'] = __('The Postal server URL is required.', 'fluentsmtp-postal');
        } elseif (!filter_var($serverUrl, FILTER_VALIDATE_URL)) {
            $errors['server_url']['url'] = __('The Postal server URL is not a valid URL.', 'fluentsmtp-postal');
        } elseif (strpos($serverUrl, 'https://') !== 0 && Arr::get($connection, 'allow_insecure_url') !== 'yes') {
            $errors['server_url']['scheme'] = __('The Postal server URL should start with https:// for secure delivery.', 'fluentsmtp-postal');
        }

        $keyStoreType = Arr::get($connection, 'key_store', 'db');

        if ($keyStoreType === 'db') {
            if (!Arr::get($connection, 'api_key')) {
                $errors['api_key']['required'] = __('The Postal API key is required.', 'fluentsmtp-postal');
            }
        } elseif ($keyStoreType === 'wp_config') {
            if (!defined('FLUENTSMTP_POSTAL_API_KEY') || !FLUENTSMTP_POSTAL_API_KEY) {
                $errors['api_key']['required'] = __('Please define FLUENTSMTP_POSTAL_API_KEY in your wp-config.php file.', 'fluentsmtp-postal');
            }
        }

        if ($errors) {
            $this->throwValidationException($errors);
        }
    }

    /**
     * Live, non-sending authentication probe.
     *
     * Postal authenticates the X-Server-API-Key before it looks anything up, so
     * a request for a non-existent message tells us everything we need:
     *   - "MessageNotFound"    => URL + key are both good.
     *   - InvalidServerAPIKey  => the key is wrong.
     *   - AccessDenied         => the key is not an API-type credential.
     *   - transport WP_Error   => the host is unreachable / TLS failed.
     *
     * @param array $connection
     * @return bool
     */
    public function checkConnection($connection)
    {
        $this->setSettings($connection);

        $params = array_merge($this->getDefaultParams(), [
            'headers'   => [
                'Accept'           => 'application/json',
                'Content-Type'     => 'application/json',
                'X-Server-API-Key' => $this->getApiKey(),
            ],
            'body'      => wp_json_encode(['id' => 0]),
            'sslverify' => Arr::get($connection, 'verify_ssl', 'yes') !== 'no',
        ]);

        $response = wp_remote_post($this->getApiUrl('messages/message'), $params);

        if (is_wp_error($response)) {
            $this->throwValidationException([
                'server_url' => [
                    'unreachable' => sprintf(
                        /* translators: %s: transport error message */
                        __('Could not reach the Postal server: %s', 'fluentsmtp-postal'),
                        $response->get_error_message()
                    ),
                ],
            ]);
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($decoded) || !isset($decoded['status'])) {
            $this->throwValidationException([
                'server_url' => [
                    'invalid' => __('The URL did not return a Postal API response. Check that it points at your Postal server.', 'fluentsmtp-postal'),
                ],
            ]);
        }

        $code = isset($decoded['data']['code']) ? $decoded['data']['code'] : '';

        // Authentication succeeded: Postal got past the key check and only then
        // failed to find message id 0.
        if ($decoded['status'] === 'error' && $code === 'MessageNotFound') {
            return true;
        }

        if (in_array($code, ['InvalidServerAPIKey', 'AccessDenied'], true)) {
            $this->throwValidationException([
                'api_key' => [
                    'invalid' => isset($this->errorMessages[$code])
                        ? $this->errorMessages[$code]
                        : __('The Postal API key was rejected.', 'fluentsmtp-postal'),
                ],
            ]);
        }

        if ($code === 'ServerSuspended') {
            $this->throwValidationException([
                'server_url' => ['suspended' => __('This Postal server has been suspended.', 'fluentsmtp-postal')],
            ]);
        }

        // Any other authenticated response also means the key worked.
        if ($decoded['status'] === 'success' || $code === 'MessageNotFound') {
            return true;
        }

        $this->throwValidationException([
            'api_key' => [
                'invalid' => Arr::get($decoded, 'data.message', __('Could not verify the Postal connection.', 'fluentsmtp-postal')),
            ],
        ]);
    }

    /**
     * Extra sender addresses this connection is allowed to send from.
     *
     * @param array $connection
     * @return array<int,string>
     */
    public function getValidSenders($connection)
    {
        $senders = [Arr::get($connection, 'sender_email')];

        foreach ((array) Arr::get($connection, 'additional_senders', []) as $email) {
            $email = is_array($email) ? Arr::get($email, 'email') : $email;
            if ($email && is_email($email)) {
                $senders[] = $email;
            }
        }

        return array_values(array_unique(array_filter($senders)));
    }
}
