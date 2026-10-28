<?php

namespace FluentSmtpPostal\Support;

use FluentSmtpPostal\Bootstrap;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads and writes Postal connections into FluentSMTP's own settings option.
 *
 * This mirrors the semantics of FluentSMTP's Models\Settings::store() (unique
 * key = md5(sender_email), connections + mappings + misc.default_connection)
 * but without the static-catalog title lookup that would reject an unknown
 * provider key. Connections written here are indistinguishable from
 * core-managed ones at send time, so FluentSMTP's logging, fallback and
 * resend-from-log all work unchanged.
 *
 * Non-Postal connections are always read and written back untouched.
 */
class ConnectionStore
{
    /**
     * Fields that hold the raw form values for a Postal connection.
     */
    public static function defaults()
    {
        return [
            'provider'           => Bootstrap::PROVIDER_KEY,
            'sender_name'        => '',
            'sender_email'       => '',
            'force_from_name'    => 'no',
            'force_from_email'   => 'yes',
            'return_path'        => 'yes',
            'server_url'         => '',
            'api_key'            => '',
            'key_store'          => 'db',
            'verify_ssl'         => 'yes',
            'allow_insecure_url' => 'no',
            'bounce'             => 'no',
            'additional_senders' => [],
        ];
    }

    /**
     * Persist a Postal connection.
     *
     * @param array       $providerSettings Sanitized provider settings, api_key in plaintext.
     * @param string|null $editingKey       Existing connection key being edited, if any.
     * @return array The full connections + mappings + misc after save.
     */
    public function save(array $providerSettings, $editingKey = null)
    {
        $providerSettings = wp_parse_args($providerSettings, self::defaults());
        $providerSettings['provider'] = Bootstrap::PROVIDER_KEY;

        $email = $providerSettings['sender_email'];

        $settings    = \fluentMailGetSettings();
        $connections = isset($settings['connections']) && is_array($settings['connections']) ? $settings['connections'] : [];
        $mappings    = isset($settings['mappings']) && is_array($settings['mappings']) ? $settings['mappings'] : [];

        // The key arrives in plaintext: SaveInterceptor has already swapped
        // FluentSMTP's mask for the stored key it stands in for.
        if ($providerSettings['key_store'] === 'wp_config') {
            $providerSettings['api_key'] = '';
        } else {
            $providerSettings['api_key'] = Crypto::encrypt($providerSettings['api_key']);
        }

        // Remove the connection being edited (its key may change with the email).
        if ($editingKey && isset($connections[$editingKey])) {
            $mappings = array_filter($mappings, function ($mappedKey) use ($editingKey) {
                return $mappedKey !== $editingKey;
            });
            unset($connections[$editingKey]);
        }

        $key = md5($email);

        // Primary sender emails already claimed by other connections.
        $primaryEmails = [];
        foreach ($connections as $connection) {
            $primaryEmails[] = $connection['provider_settings']['sender_email'] ?? '';
        }

        // Build the sender -> key mappings (primary + additional senders).
        $senderEmails = [$email];
        foreach ((array) $providerSettings['additional_senders'] as $extra) {
            $extra = is_array($extra) ? ($extra['email'] ?? '') : $extra;
            if ($extra && is_email($extra) && !in_array($extra, $primaryEmails, true)) {
                $senderEmails[] = $extra;
            }
        }
        $senderEmails = array_unique(array_filter($senderEmails));

        foreach ($senderEmails as $senderEmail) {
            $mappings[$senderEmail] = $key;
        }

        $connections[$key] = [
            'title'             => 'Postal',
            'provider_settings' => $providerSettings,
        ];

        // Drop mappings that point at connections which no longer exist.
        $validKeys = array_keys($connections);
        $mappings = array_filter($mappings, function ($mappedKey) use ($validKeys) {
            return in_array($mappedKey, $validKeys, true);
        });

        $settings['connections'] = $connections;
        $settings['mappings']    = $mappings;

        // Default connection: adopt this one if none set, or if it was the one edited.
        //
        // Only that one key is written. The rest of misc comes from FluentSMTP's
        // own defaults (app/Services/Mailer/Providers/config.php: log_emails,
        // disable_fluentcrm_logs, simulate_emails, send_as_text, fallback_connection)
        // and merging a hand-rolled default list over it here would quietly turn
        // email logging on for a site that had chosen to leave it off.
        $misc = isset($settings['misc']) && is_array($settings['misc']) ? $settings['misc'] : [];
        $currentDefault = isset($misc['default_connection']) ? $misc['default_connection'] : '';
        if (empty($currentDefault) || ($editingKey && $currentDefault === $editingKey)) {
            $misc['default_connection'] = $key;
        }
        $settings['misc'] = $misc;

        \fluentMailSetSettings($settings);
        $this->bustCaches($senderEmails);

        return [
            'connections' => $settings['connections'],
            'mappings'    => $settings['mappings'],
            'misc'        => $settings['misc'],
        ];
    }

    /**
     * Clear FluentSMTP's in-process settings and provider caches so the change
     * takes effect within the same request (e.g. before a test email).
     *
     * @param array<int,string> $emails
     */
    protected function bustCaches(array $emails = [])
    {
        if (function_exists('fluentMailGetSettings')) {
            \fluentMailGetSettings([], false);
        }
        if (function_exists('fluentMailGetProvider')) {
            foreach ($emails as $email) {
                \fluentMailGetProvider($email, true);
            }
        }
    }
}
