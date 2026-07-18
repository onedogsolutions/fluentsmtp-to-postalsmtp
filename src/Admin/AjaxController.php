<?php

namespace FluentSmtpPostal\Admin;

use Exception;
use FluentSmtpPostal\Support\ConnectionStore;
use FluentMail\Includes\Support\ValidationException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * admin-ajax endpoints for the Postal setup screen.
 *
 * Endpoints (all require manage_options + the fluentsmtp_postal nonce):
 *   fluentsmtp_postal_save             — validate, live-check, and store a connection
 *   fluentsmtp_postal_delete           — remove a connection
 *   fluentsmtp_postal_test_connection  — non-sending auth probe
 *   fluentsmtp_postal_send_test_email  — send a real test email through the handler
 *   fluentsmtp_postal_get              — refresh the connection list
 */
class AjaxController
{
    const HANDLER = 'FluentMail\\App\\Services\\Mailer\\Providers\\Postal\\Handler';

    /** @var string|null */
    protected $lastMailError = null;

    public function register()
    {
        add_action('wp_ajax_fluentsmtp_postal_save', [$this, 'save']);
        add_action('wp_ajax_fluentsmtp_postal_delete', [$this, 'delete']);
        add_action('wp_ajax_fluentsmtp_postal_test_connection', [$this, 'testConnection']);
        add_action('wp_ajax_fluentsmtp_postal_send_test_email', [$this, 'sendTestEmail']);
        add_action('wp_ajax_fluentsmtp_postal_get', [$this, 'getConnections']);
    }

    protected function verify()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do this.', 'fluentsmtp-postal')], 403);
        }
        check_ajax_referer('fluentsmtp_postal', 'nonce');
    }

    public function getConnections()
    {
        $this->verify();
        wp_send_json_success(['connections' => (new ConnectionStore())->getPostalConnections()]);
    }

    public function save()
    {
        $this->verify();

        $connection = $this->sanitizeConnection($this->rawConnection());
        $editingKey = isset($_POST['connection_key']) ? sanitize_text_field(wp_unslash($_POST['connection_key'])) : null;

        try {
            $handler = $this->makeHandler();

            // Field-level validation (sender email + provider fields).
            $handler->validateBasicInformation($connection);
            $handler->validateProviderInformation($connection);

            // Live auth probe unless the caller opted out.
            $skipCheck = !empty($_POST['skip_connection_check']);
            if (!$skipCheck) {
                // The stored key is blank on edit ("keep existing"); resolve the
                // real key so the probe uses valid credentials.
                $handler->checkConnection($this->connectionForLiveCheck($connection, $editingKey));
            }

            $result = (new ConnectionStore())->save($connection, $editingKey ?: null);

            wp_send_json_success([
                'message'     => __('Postal connection saved.', 'fluentsmtp-postal'),
                'connections' => (new ConnectionStore())->getPostalConnections(),
                'mappings'    => $result['mappings'],
                'misc'        => $result['misc'],
            ]);
        } catch (ValidationException $e) {
            wp_send_json_error($e->errors(), 422);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()], 422);
        }
    }

    public function testConnection()
    {
        $this->verify();

        $connection = $this->sanitizeConnection($this->rawConnection());
        $editingKey = isset($_POST['connection_key']) ? sanitize_text_field(wp_unslash($_POST['connection_key'])) : null;

        try {
            $handler = $this->makeHandler();
            $handler->validateProviderInformation($this->connectionForLiveCheck($connection, $editingKey));
            $handler->checkConnection($this->connectionForLiveCheck($connection, $editingKey));
            wp_send_json_success(['message' => __('Connection to Postal verified successfully.', 'fluentsmtp-postal')]);
        } catch (ValidationException $e) {
            wp_send_json_error($e->errors(), 422);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()], 422);
        }
    }

    public function delete()
    {
        $this->verify();

        $key = isset($_POST['connection_key']) ? sanitize_text_field(wp_unslash($_POST['connection_key'])) : '';
        $store = new ConnectionStore();

        if (!$key || !$store->ownsConnection($key)) {
            wp_send_json_error(['message' => __('Unknown Postal connection.', 'fluentsmtp-postal')], 404);
        }

        $result = $store->delete($key);

        wp_send_json_success([
            'message'     => __('Postal connection deleted.', 'fluentsmtp-postal'),
            'connections' => $store->getPostalConnections(),
            'mappings'    => $result['mappings'],
            'misc'        => $result['misc'],
        ]);
    }

    public function sendTestEmail()
    {
        $this->verify();

        $to  = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $key = isset($_POST['connection_key']) ? sanitize_text_field(wp_unslash($_POST['connection_key'])) : '';

        if (!$to || !is_email($to)) {
            wp_send_json_error(['message' => __('A valid recipient email is required.', 'fluentsmtp-postal')], 422);
        }

        $settings   = \fluentMailGetSettings();
        $connection = $settings['connections'][$key]['provider_settings'] ?? null;

        if (!$connection || ($connection['provider'] ?? '') !== \FluentSmtpPostal\Bootstrap::PROVIDER_KEY) {
            wp_send_json_error(['message' => __('Save the connection before sending a test email.', 'fluentsmtp-postal')], 422);
        }

        $from = $connection['sender_email'];
        $name = $connection['sender_name'] ?: get_bloginfo('name');

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            sprintf('From: %s <%s>', $name, $from),
        ];

        $this->lastMailError = null;
        add_action('wp_mail_failed', [$this, 'captureMailError']);

        $subject = __('FluentSMTP + Postal test email', 'fluentsmtp-postal');
        $body    = sprintf(
            '<p>%s</p><p>%s</p>',
            esc_html__('This is a test email sent through the Postal HTTP API via FluentSMTP.', 'fluentsmtp-postal'),
            esc_html(sprintf(/* translators: %s: site name */ __('Sent from %s.', 'fluentsmtp-postal'), get_bloginfo('name')))
        );

        $sent = wp_mail($to, $subject, $body, $headers);

        remove_action('wp_mail_failed', [$this, 'captureMailError']);

        if ($sent) {
            wp_send_json_success([
                'message' => sprintf(/* translators: %s: recipient */ __('Test email sent to %s via Postal.', 'fluentsmtp-postal'), $to),
            ]);
        }

        wp_send_json_error([
            'message' => $this->lastMailError ?: __('The test email could not be sent. Check the FluentSMTP email log for details.', 'fluentsmtp-postal'),
        ], 422);
    }

    /**
     * @param \WP_Error $error
     */
    public function captureMailError($error)
    {
        if (is_wp_error($error)) {
            $this->lastMailError = $error->get_error_message();
        }
    }

    /**
     * The raw posted connection array (still slashed).
     *
     * @return array
     */
    protected function rawConnection()
    {
        if (isset($_POST['connection']) && is_array($_POST['connection'])) {
            return wp_unslash($_POST['connection']);
        }
        return [];
    }

    /**
     * @return \FluentMail\App\Services\Mailer\Providers\Postal\Handler
     */
    protected function makeHandler()
    {
        if (function_exists('fluentMail')) {
            $factory = \fluentMail('FluentMail\\App\\Services\\Mailer\\Providers\\Factory');
            if ($factory && method_exists($factory, 'make')) {
                return $factory->make(\FluentSmtpPostal\Bootstrap::PROVIDER_KEY);
            }
        }

        $class = self::HANDLER;
        return new $class();
    }

    /**
     * When editing with a blank key ("keep existing"), swap in the stored,
     * decrypted key so the live probe authenticates. wp_config keys resolve in
     * the handler itself.
     *
     * @param array       $connection
     * @param string|null $editingKey
     * @return array
     */
    protected function connectionForLiveCheck(array $connection, $editingKey)
    {
        if ($connection['key_store'] === 'wp_config' || $connection['api_key'] !== '' || !$editingKey) {
            return $connection;
        }

        $settings = \fluentMailGetSettings();
        $stored   = $settings['connections'][$editingKey]['provider_settings']['api_key'] ?? '';
        if ($stored) {
            $connection['api_key'] = \FluentSmtpPostal\Support\Crypto::decrypt($stored);
        }

        return $connection;
    }

    /**
     * Sanitize the posted connection into clean provider_settings.
     *
     * @param array $raw
     * @return array
     */
    protected function sanitizeConnection(array $raw)
    {
        $yn = function ($value, $default = 'no') {
            $value = is_string($value) ? strtolower($value) : $value;
            return in_array($value, ['yes', '1', 1, true, 'true'], true) ? 'yes' : ($value === '' ? $default : 'no');
        };

        $serverUrl = isset($raw['server_url']) ? esc_url_raw(trim((string) $raw['server_url'])) : '';

        $additional = [];
        if (!empty($raw['additional_senders']) && is_array($raw['additional_senders'])) {
            foreach ($raw['additional_senders'] as $entry) {
                $email = is_array($entry) ? ($entry['email'] ?? '') : $entry;
                $email = sanitize_email($email);
                if ($email && is_email($email)) {
                    $additional[] = $email;
                }
            }
        }

        $keyStore = (isset($raw['key_store']) && $raw['key_store'] === 'wp_config') ? 'wp_config' : 'db';

        return [
            'provider'           => \FluentSmtpPostal\Bootstrap::PROVIDER_KEY,
            'sender_name'        => isset($raw['sender_name']) ? sanitize_text_field($raw['sender_name']) : '',
            'sender_email'       => isset($raw['sender_email']) ? sanitize_email($raw['sender_email']) : '',
            'force_from_name'    => $yn($raw['force_from_name'] ?? 'no', 'no'),
            'force_from_email'   => $yn($raw['force_from_email'] ?? 'yes', 'yes'),
            'return_path'        => $yn($raw['return_path'] ?? 'yes', 'yes'),
            'server_url'         => untrailingslashit($serverUrl),
            'api_key'            => isset($raw['api_key']) ? trim((string) $raw['api_key']) : '',
            'key_store'          => $keyStore,
            'verify_ssl'         => $yn($raw['verify_ssl'] ?? 'yes', 'yes'),
            'allow_insecure_url' => $yn($raw['allow_insecure_url'] ?? 'no', 'no'),
            'bounce'             => $yn($raw['bounce'] ?? 'no', 'no'),
            'additional_senders' => $additional,
        ];
    }
}
