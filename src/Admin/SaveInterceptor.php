<?php

namespace FluentSmtpPostal\Admin;

use Exception;
use FluentSmtpPostal\Bootstrap;
use FluentSmtpPostal\Support\ConnectionStore;
use FluentSmtpPostal\Support\Crypto;
use FluentMail\Includes\Support\ValidationException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Saves Postal connections submitted from FluentSMTP's own connection screen.
 *
 * FluentSMTP's Save button posts to its `settings` route. Its controller cannot
 * store a provider it does not ship: Settings::store() reads the connection's
 * title from the static provider catalog in config.php, which has no filter, so
 * for `postal` it raises an undefined-index warning (which breaks the JSON
 * response under WP_DEBUG) and saves a null title.
 *
 * So this runs first on the same AJAX action and answers for Postal connections
 * only, in exactly the shape FluentSMTP's ConnectionWizard expects. Every other
 * provider returns untouched to FluentSMTP's own handler.
 */
class SaveInterceptor
{
    const HANDLER = 'FluentMail\\App\\Services\\Mailer\\Providers\\Postal\\Handler';

    /**
     * FluentSMTP's SecretMasker (2.4+). Stands in for every saved secret it sends
     * to the browser.
     */
    const MASKER = 'FluentMail\\App\\Services\\SecretMasker';

    /**
     * Mirrors the SECRET_FIELDS FluentSMTP masks, for the fallback below.
     */
    const SECRET_FIELDS = ['password', 'api_key', 'secret_key', 'access_key', 'client_secret'];

    public function register()
    {
        // FluentSMTP's router names its actions FLUENTMAIL-<method>-<route>, and
        // its JS $post('settings') posts to this one. Priority 1 puts this ahead
        // of FluentSMTP's own callback, which never runs for a Postal save
        // because wp_send_json_*() ends the request.
        add_action('wp_ajax_' . FLUENTMAIL . '-post-settings', [$this, 'maybeHandle'], 1);
    }

    public function maybeHandle()
    {
        $raw = $this->rawConnection();

        if (($raw['provider'] ?? '') !== Bootstrap::PROVIDER_KEY) {
            return;
        }

        $this->verify();

        $editingKey = $this->editingKey();
        $connection = $this->sanitizeConnection($raw);

        try {
            $connection = $this->resolveMaskedKey($connection, $editingKey);

            $handler = $this->makeHandler();

            $handler->validateBasicInformation($connection);
            $handler->validateProviderInformation($connection);
            $handler->checkConnection($connection);

            $result = (new ConnectionStore())->save($connection, $editingKey);

            wp_send_json_success([
                'message'     => __('Settings saved.', 'fluentsmtp-postal'),
                'connections' => $this->maskConnections($result['connections']),
                'mappings'    => $result['mappings'],
                'misc'        => $this->completeMisc($result),
            ]);
        } catch (ValidationException $e) {
            wp_send_json_error($e->errors(), 422);
        } catch (Exception $e) {
            // FluentSMTP's wizard records the payload as field errors; putting the
            // message on server_url is what makes it visible next to the form.
            wp_send_json_error([
                'message'    => $e->getMessage(),
                'server_url' => ['error' => $e->getMessage()],
            ], 422);
        }
    }

    /**
     * The same gate as FluentSMTP's Controller::verify().
     */
    protected function verify()
    {
        $capability = function_exists('fluentMailManageCapability') ? \fluentMailManageCapability() : 'manage_options';

        if (!current_user_can($capability)) {
            wp_send_json_error([
                'message' => __('You do not have permission to do this.', 'fluentsmtp-postal'),
            ], 403);
        }

        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';

        if (!wp_verify_nonce($nonce, FLUENTMAIL)) {
            wp_send_json_error([
                'message' => __('Security check failed. Please reload the page.', 'fluentsmtp-postal'),
            ], 403);
        }
    }

    /**
     * The connection being edited, or null for a new one ('0' is FluentSMTP's
     * own "new").
     *
     * @return string|null
     */
    protected function editingKey()
    {
        $key = isset($_POST['connection_key']) ? sanitize_text_field(wp_unslash($_POST['connection_key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        return ($key === '' || $key === '0') ? null : $key;
    }

    /**
     * The raw posted connection array, unslashed.
     *
     * @return array
     */
    protected function rawConnection()
    {
        if (isset($_POST['connection']) && is_array($_POST['connection'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return wp_unslash($_POST['connection']); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }

        return [];
    }

    /**
     * Swap FluentSMTP's mask for the key it stands in for.
     *
     * The form posts the mask back when the admin left a saved key alone. The real
     * key is restored from the connection being edited - and only from a Postal
     * one, so a connection switched over from another provider cannot inherit that
     * provider's credential. Anything else that arrives as the mask is not a key,
     * and is emptied so the validator asks for one.
     *
     * @param array       $connection
     * @param string|null $editingKey
     * @return array
     */
    protected function resolveMaskedKey(array $connection, $editingKey)
    {
        if ($connection['api_key'] !== $this->mask()) {
            return $connection;
        }

        $connection['api_key'] = '';

        if (!$editingKey) {
            return $connection;
        }

        $settings = \fluentMailGetSettings();
        $stored   = $settings['connections'][$editingKey]['provider_settings'] ?? [];

        if (($stored['provider'] ?? '') === Bootstrap::PROVIDER_KEY && !empty($stored['api_key'])) {
            $connection['api_key'] = Crypto::decrypt($stored['api_key']);
        }

        return $connection;
    }

    /**
     * @return string
     */
    protected function mask()
    {
        $masker = self::MASKER;

        return defined($masker . '::MASK') ? constant($masker . '::MASK') : '__MASKED_KEY_ENCRYPTED__';
    }

    /**
     * The connections as FluentSMTP itself returns them: secrets masked.
     *
     * The wizard installs these straight into the page's shared state, so they
     * must never carry a real credential - including other providers' ones.
     *
     * @param array $connections
     * @return array
     */
    protected function maskConnections(array $connections)
    {
        $masker = self::MASKER;

        if (class_exists($masker) && method_exists($masker, 'maskConnections')) {
            return $masker::maskConnections($connections);
        }

        foreach ($connections as $key => $connection) {
            foreach (self::SECRET_FIELDS as $field) {
                if (!empty($connection['provider_settings'][$field])) {
                    $connections[$key]['provider_settings'][$field] = $this->mask();
                }
            }
            unset($connections[$key]['provider_settings']['access_token'], $connections[$key]['provider_settings']['refresh_token']);
        }

        return $connections;
    }

    /**
     * The misc settings filled out with FluentSMTP's defaults.
     *
     * The wizard installs this straight into the page's settings.misc, where the
     * General Settings switches bind to each key. What is stored holds only the
     * keys ever saved, and a missing one reaches a switch as undefined.
     *
     * @param array $settings
     * @return array
     */
    protected function completeMisc(array $settings)
    {
        $model = 'FluentMail\\App\\Models\\Settings';

        if (class_exists($model) && method_exists($model, 'getMisc')) {
            return (new $model())->getMisc($settings);
        }

        return $settings['misc'];
    }

    /**
     * @return \FluentMail\App\Services\Mailer\Providers\Postal\Handler
     */
    protected function makeHandler()
    {
        if (function_exists('fluentMail')) {
            $factory = \fluentMail('FluentMail\\App\\Services\\Mailer\\Providers\\Factory');
            if ($factory && method_exists($factory, 'make')) {
                return $factory->make(Bootstrap::PROVIDER_KEY);
            }
        }

        $class = self::HANDLER;
        return new $class();
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
            'provider'           => Bootstrap::PROVIDER_KEY,
            'sender_name'        => isset($raw['sender_name']) ? wp_specialchars_decode(sanitize_text_field($raw['sender_name']), ENT_QUOTES) : '',
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
