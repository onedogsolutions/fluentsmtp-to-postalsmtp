<?php

/**
 * Plugin Name:       Postal API Connector for FluentSMTP
 * Plugin URI:        https://github.com/onedogsolutions/fluentsmtp-to-postalsmtp
 * Description:       Adds a Postal connection to FluentSMTP that delivers mail over the Postal HTTP API instead of SMTP, avoiding SMTP TLS issues on self-hosted Postal servers.
 * Version:           1.0.0
 * Requires at least: 5.5
 * Requires PHP:      7.4
 * Author:            One Dog Solutions
 * Author URI:        https://onedog.solutions
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fluentsmtp-postal
 * Domain Path:       /language
 *
 * This is a companion plugin for FluentSMTP (https://fluentsmtp.com). It does
 * not replace FluentSMTP; it registers an additional "Postal" provider into the
 * FluentSMTP mailer so WordPress mail can be delivered through a self-hosted
 * Postal server's HTTP API (/api/v1/send/*) rather than SMTP.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FLUENTSMTP_POSTAL_VERSION', '1.0.0');
define('FLUENTSMTP_POSTAL_FILE', __FILE__);
define('FLUENTSMTP_POSTAL_DIR', plugin_dir_path(__FILE__));
define('FLUENTSMTP_POSTAL_URL', plugin_dir_url(__FILE__));
define('FLUENTSMTP_POSTAL_SLUG', 'fluentsmtp-postal');

/**
 * Lightweight PSR-4 autoloader for the plugin's own classes and for the
 * drop-in Postal provider classes that live in FluentSMTP's namespace.
 *
 * Mapping:
 *   FluentSmtpPostal\*                                  -> src/*
 *   FluentMail\App\Services\Mailer\Providers\Postal\*   -> src/Providers/Postal/*
 *
 * The provider classes are authored inside FluentSMTP's own namespace so they
 * can be copied verbatim into fluent-smtp/app/Services/Mailer/Providers/Postal
 * if the connector is ever merged upstream. FluentSMTP's autoloader will not
 * find these files (they do not exist in its tree), so it silently defers and
 * this autoloader resolves them.
 */
spl_autoload_register(function ($class) {
    $maps = [
        'FluentSmtpPostal\\' => FLUENTSMTP_POSTAL_DIR . 'src/',
        'FluentMail\\App\\Services\\Mailer\\Providers\\Postal\\' => FLUENTSMTP_POSTAL_DIR . 'src/Providers/Postal/',
    ];

    foreach ($maps as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) {
            continue;
        }
        $relative = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require $file;
        }
        return;
    }
});

/**
 * Boot the plugin once all plugins are loaded so we can reliably detect whether
 * FluentSMTP is present before wiring anything up.
 */
add_action('plugins_loaded', function () {
    (new \FluentSmtpPostal\Bootstrap())->init();
}, 20);
