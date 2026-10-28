<?php

namespace FluentSmtpPostal;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Activation and deactivation housekeeping.
 *
 * FluentSMTP resolves a stored connection's provider through its container and
 * throws InvalidArgumentException("Unknown mail provider.") when the key is not
 * bound - which, for a Postal connection, is exactly what deactivating this
 * plugin produces. That exception is raised inside wp_mail(), so every email on
 * the site routed to a Postal sender would fatal.
 *
 * So a Postal connection is parked on deactivation: it is lifted out of
 * FluentSMTP's connections and mappings (so nothing routes to it) and kept in
 * an option; activating again puts it back, mappings and default connection
 * included. Nothing is deleted, and no half-parked state can be saved by a
 * concurrent request because the whole array is written in one update.
 */
class Lifecycle
{
    /**
     * Connections lifted out of FluentSMTP while the plugin was inactive.
     */
    const PARKED_OPTION = 'fluentsmtp_postal_parked_connections';

    /**
     * Outcome of the last restore, shown once on the next admin screen.
     */
    const RESTORED_OPTION = 'fluentsmtp_postal_restored_report';

    public static function activate()
    {
        self::restoreParkedConnections();
    }

    public static function deactivate()
    {
        self::parkPostalConnections();
    }

    /**
     * Restore parked connections when FluentSMTP is present.
     *
     * Called on activation and again on every boot, because activation order is
     * not the admin's to control: activating this plugin before FluentSMTP leaves
     * nothing to restore at that moment, and the parked connections would
     * otherwise sit there until someone happened to deactivate and reactivate.
     * The check is free - the option is autoloaded with the rest of the site's
     * options.
     */
    public static function maybeRestoreParkedConnections()
    {
        if (!self::isFluentSmtpAvailable()) {
            return;
        }

        if (!get_option(self::PARKED_OPTION)) {
            return;
        }

        self::restoreParkedConnections();
    }

    /**
     * Move every Postal connection out of FluentSMTP's settings.
     */
    protected static function parkPostalConnections()
    {
        if (!self::isFluentSmtpAvailable()) {
            // FluentSMTP is gone too, so there is no routing to break and no
            // settings to read. Leaving the option untouched keeps the data for
            // the case where FluentSMTP comes back.
            return;
        }

        $settings = \fluentMailGetSettings();

        if (!is_array($settings) || empty($settings['connections']) || !is_array($settings['connections'])) {
            return;
        }

        $connections = $settings['connections'];
        $mappings    = isset($settings['mappings']) && is_array($settings['mappings']) ? $settings['mappings'] : [];

        $parked  = [];
        $removed = [];

        foreach ($connections as $key => $connection) {
            $providerSettings = isset($connection['provider_settings']) ? $connection['provider_settings'] : [];

            if (($providerSettings['provider'] ?? '') !== Bootstrap::PROVIDER_KEY) {
                continue;
            }

            $parked[$key]  = $connection;
            $removed[]     = (string) $key;
        }

        if (!$removed) {
            return;
        }

        foreach ($removed as $key) {
            unset($connections[$key]);
        }

        $mappings = array_filter($mappings, function ($mappedKey) use ($removed) {
            return !in_array((string) $mappedKey, $removed, true);
        });

        $misc = isset($settings['misc']) && is_array($settings['misc']) ? $settings['misc'] : [];

        foreach (['default_connection', 'fallback_connection'] as $miscKey) {
            if (isset($misc[$miscKey]) && in_array((string) $misc[$miscKey], $removed, true)) {
                // A fallback pointing at nothing is cleared; the default falls
                // back to whichever connection is left so mail keeps a route.
                $misc[$miscKey] = ($miscKey === 'default_connection' && $connections)
                    ? array_key_first($connections)
                    : '';
            }
        }

        // Merge with anything already parked (e.g. a second deactivation after a
        // manual re-activation that could not restore).
        $existing = get_option(self::PARKED_OPTION);
        if (is_array($existing)) {
            $parked = array_merge($existing, $parked);
        }

        update_option(self::PARKED_OPTION, $parked);

        $settings['connections'] = $connections;
        $settings['mappings']    = $mappings;
        $settings['misc']        = $misc;

        \fluentMailSetSettings($settings);
    }

    /**
     * Put parked connections back, skipping any From address that has since
     * been claimed by another connection - FluentSMTP keys connections by
     * md5(sender_email), so restoring a duplicate would silently take the
     * address back from whichever connection the admin created meanwhile.
     */
    protected static function restoreParkedConnections()
    {
        $parked = get_option(self::PARKED_OPTION);

        if (!is_array($parked) || !$parked) {
            return;
        }

        if (!self::isFluentSmtpAvailable()) {
            return;
        }

        $settings = \fluentMailGetSettings();

        if (!is_array($settings)) {
            $settings = [];
        }

        $connections = isset($settings['connections']) && is_array($settings['connections']) ? $settings['connections'] : [];
        $mappings    = isset($settings['mappings']) && is_array($settings['mappings']) ? $settings['mappings'] : [];

        $restored = 0;
        $skipped  = 0;
        $lastKey  = '';

        foreach ($parked as $key => $connection) {
            $providerSettings = isset($connection['provider_settings']) ? $connection['provider_settings'] : [];

            if (($providerSettings['provider'] ?? '') !== Bootstrap::PROVIDER_KEY) {
                continue;
            }

            $senderEmail = isset($providerSettings['sender_email']) ? $providerSettings['sender_email'] : '';

            if ($senderEmail && self::emailClaimed($connections, $senderEmail)) {
                $skipped++;
                continue;
            }

            $connections[$key] = $connection;
            $lastKey           = $key;

            foreach (self::senderEmails($providerSettings) as $email) {
                $mappings[$email] = $key;
            }

            $restored++;
        }

        delete_option(self::PARKED_OPTION);

        if (!$restored) {
            if ($skipped) {
                update_option(self::RESTORED_OPTION, ['restored' => 0, 'skipped' => $skipped]);
            }
            return;
        }

        $settings['connections'] = $connections;
        $settings['mappings']    = $mappings;

        $misc = isset($settings['misc']) && is_array($settings['misc']) ? $settings['misc'] : [];

        if (empty($misc['default_connection'])) {
            $misc['default_connection'] = $lastKey;
        }

        $settings['misc'] = $misc;

        \fluentMailSetSettings($settings);

        update_option(self::RESTORED_OPTION, ['restored' => $restored, 'skipped' => $skipped]);
    }

    /**
     * @param array<string,array> $connections
     * @param string              $email
     * @return bool
     */
    protected static function emailClaimed($connections, $email)
    {
        foreach ($connections as $connection) {
            $providerSettings = isset($connection['provider_settings']) ? $connection['provider_settings'] : [];

            if (isset($providerSettings['sender_email']) && $providerSettings['sender_email'] === $email) {
                return true;
            }

            if (in_array($email, self::senderEmails($providerSettings), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array $providerSettings
     * @return array<int,string>
     */
    protected static function senderEmails($providerSettings)
    {
        $emails = [];

        if (!empty($providerSettings['sender_email'])) {
            $emails[] = $providerSettings['sender_email'];
        }

        foreach (isset($providerSettings['additional_senders']) ? (array) $providerSettings['additional_senders'] : [] as $entry) {
            $email = is_array($entry) ? ($entry['email'] ?? '') : $entry;

            if ($email) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique(array_filter($emails)));
    }

    /**
     * @return bool
     */
    protected static function isFluentSmtpAvailable()
    {
        return defined('FLUENTMAIL')
            && class_exists('FluentMail\\App\\Services\\Mailer\\BaseHandler')
            && function_exists('fluentMailGetSettings')
            && function_exists('fluentMailSetSettings');
    }
}
