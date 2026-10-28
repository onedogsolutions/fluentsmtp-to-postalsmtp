<?php

namespace FluentSmtpPostal;

use FluentSmtpPostal\Admin\FluentSmtpIntegration;
use FluentSmtpPostal\Admin\SaveInterceptor;

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

        // Reactivating after a deactivation that parked Postal connections (or
        // activating this plugin after FluentSMTP was installed) puts them back.
        // The check costs one autoloaded option read and returns immediately in
        // the normal case.
        Lifecycle::maybeRestoreParkedConnections();
        add_action('admin_notices', [$this, 'renderRestoredNotice']);

        if (is_admin()) {
            (new FluentSmtpIntegration())->register();
            (new SaveInterceptor())->register();
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

    /**
     * Report the outcome of a restore, once.
     *
     * The report is written by Lifecycle during this request or the activation
     * request that preceded it, and deleted here so it is shown exactly once to
     * whoever loads an admin screen next.
     */
    public function renderRestoredNotice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $report = get_option(Lifecycle::RESTORED_OPTION);

        if (!is_array($report)) {
            return;
        }

        delete_option(Lifecycle::RESTORED_OPTION);

        $restored = isset($report['restored']) ? (int) $report['restored'] : 0;
        $skipped  = isset($report['skipped']) ? (int) $report['skipped'] : 0;

        echo '<div class="notice notice-success is-dismissible"><p>';

        if ($restored) {
            echo esc_html(sprintf(
                /* translators: %d: number of restored Postal connections */
                _n(
                    '%d Postal connection was restored in FluentSMTP.',
                    '%d Postal connections were restored in FluentSMTP.',
                    $restored,
                    'fluentsmtp-postal'
                ),
                $restored
            ));
        } else {
            esc_html_e('No Postal connections were restored in FluentSMTP.', 'fluentsmtp-postal');
        }

        if ($skipped) {
            echo ' ' . esc_html(sprintf(
                /* translators: %d: number of skipped Postal connections */
                _n(
                    '%d was skipped because its From address is already used by another connection.',
                    '%d were skipped because their From address is already used by another connection.',
                    $skipped,
                    'fluentsmtp-postal'
                ),
                $skipped
            ));
        }

        echo '</p></div>';
    }
}
