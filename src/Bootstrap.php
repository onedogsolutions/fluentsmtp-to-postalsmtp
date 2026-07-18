<?php

namespace FluentSmtpPostal;

use FluentSmtpPostal\Admin\AdminPage;
use FluentSmtpPostal\Admin\AjaxController;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wires the plugin into WordPress and FluentSMTP.
 *
 * Responsibilities are intentionally thin: detect FluentSMTP, register the
 * Postal handler into FluentSMTP's IoC container when it boots, and load the
 * admin surface. Everything else lives in dedicated classes.
 */
class Bootstrap
{
    /**
     * The provider key used everywhere: the FluentSMTP config key, the container
     * alias, and the value stored in each connection's provider_settings.provider.
     */
    const PROVIDER_KEY = 'postal';

    public function init()
    {
        // FluentSMTP defines the FLUENTMAIL constant when it loads. If it is not
        // present, FluentSMTP is missing or inactive: show a notice and stop.
        if (!defined('FLUENTMAIL') || !class_exists('FluentMail\\App\\Services\\Mailer\\BaseHandler')) {
            add_action('admin_notices', [$this, 'renderMissingDependencyNotice']);
            return;
        }

        // Register the Postal handler into FluentSMTP's container. The
        // fluentMail_loaded action hands us the Application (container) instance.
        // If FluentSMTP has already fired it (load-order), register immediately.
        add_action('fluentMail_loaded', [ProviderRegistrar::class, 'register'], 10, 1);
        if (did_action('fluentMail_loaded')) {
            ProviderRegistrar::register(\fluentMail());
        }

        if (is_admin()) {
            (new AdminPage())->register();
            (new AjaxController())->register();
        }

        load_plugin_textdomain(
            'fluentsmtp-postal',
            false,
            dirname(plugin_basename(FLUENTSMTP_POSTAL_FILE)) . '/language'
        );
    }

    public function renderMissingDependencyNotice()
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        $install = wp_nonce_url(
            self_admin_url('plugin-install.php?tab=plugin-information&plugin=fluent-smtp&TB_iframe=true&width=600&height=550'),
            'install-plugin_fluent-smtp'
        );

        echo '<div class="notice notice-error"><p>';
        echo wp_kses_post(sprintf(
            /* translators: %s: link to install FluentSMTP */
            __('<strong>Postal API Connector for FluentSMTP</strong> requires the FluentSMTP plugin to be installed and active. %s', 'fluentsmtp-postal'),
            '<a href="' . esc_url($install) . '" class="thickbox">' . esc_html__('Install FluentSMTP', 'fluentsmtp-postal') . '</a>'
        ));
        echo '</p></div>';
    }
}
