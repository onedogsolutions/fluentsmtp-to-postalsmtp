<?php

namespace FluentSmtpPostal\Admin;

use FluentSmtpPostal\Bootstrap;
use FluentSmtpPostal\Support\Crypto;
use FluentSmtpPostal\Support\ConnectionStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the Postal setup screen and wires it into the FluentSMTP admin.
 *
 * The setup screen is a Vue 2 + Element UI app built with the same toolchain
 * and component conventions as FluentSMTP itself (see resources/admin), so the
 * provider partial (Postal.vue) can be lifted straight into fluent-smtp.
 */
class AdminPage
{
    const MENU_SLUG   = 'fluentsmtp-postal';
    const SCRIPT_HANDLE = 'fluentsmtp-postal-admin';

    public function register()
    {
        add_action('admin_menu', [$this, 'addMenu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);

        // Make Postal connections render with a logo/title inside FluentSMTP's
        // own connection list by injecting the provider metadata its Vue app
        // reads. This runs on FluentSMTP's page only and never mutates settings.
        add_action('admin_enqueue_scripts', [$this, 'injectProviderIntoFluentSmtp'], 30);
    }

    public function addMenu()
    {
        add_submenu_page(
            'options-general.php',
            __('Postal for FluentSMTP', 'fluentsmtp-postal'),
            __('Postal (FluentSMTP)', 'fluentsmtp-postal'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'renderApp'],
            17
        );
    }

    public function renderApp()
    {
        echo '<div class="wrap"><div id="fluentsmtp_postal_app"></div></div>';
    }

    /**
     * @param string $hook
     */
    public function enqueue($hook)
    {
        if ($hook !== 'settings_page_' . self::MENU_SLUG) {
            return;
        }

        $js  = FLUENTSMTP_POSTAL_URL . 'assets/admin/js/postal-admin.js';
        $css = FLUENTSMTP_POSTAL_URL . 'assets/admin/css/postal-admin.css';

        wp_enqueue_script('jquery');
        wp_enqueue_script(self::SCRIPT_HANDLE, $js, ['jquery'], FLUENTSMTP_POSTAL_VERSION, true);

        if (file_exists(FLUENTSMTP_POSTAL_DIR . 'assets/admin/css/postal-admin.css')) {
            wp_enqueue_style(self::SCRIPT_HANDLE, $css, [], FLUENTSMTP_POSTAL_VERSION);
        }

        $store = new ConnectionStore();
        $user  = wp_get_current_user();

        wp_localize_script(self::SCRIPT_HANDLE, 'FluentSmtpPostal', [
            'slug'             => Bootstrap::PROVIDER_KEY,
            'ajaxurl'          => admin_url('admin-ajax.php'),
            'nonce'            => wp_create_nonce('fluentsmtp_postal'),
            'assets_url'       => FLUENTSMTP_POSTAL_URL . 'assets/',
            'plugin_url'       => FLUENTSMTP_POSTAL_URL,
            'fluentsmtp_url'   => admin_url('options-general.php?page=fluent-mail#/connections'),
            'user_email'       => $user->user_email,
            'crypto_available' => Crypto::isAvailable(),
            'wp_config_const'  => 'FLUENTSMTP_POSTAL_API_KEY',
            'defaults'         => ConnectionStore::defaults(),
            'connections'      => $store->getPostalConnections(),
            'trans'            => [],
        ]);
    }

    /**
     * Inject the Postal provider descriptor into FluentSMTP's admin app so our
     * connections show a logo and title in its list. Cosmetic and defensive:
     * guarded by the existence of the FluentSMTP boot handle.
     *
     * @param string $hook
     */
    public function injectProviderIntoFluentSmtp($hook)
    {
        if ($hook !== 'settings_page_fluent-mail') {
            return;
        }

        if (!wp_script_is('fluent_mail_admin_app_boot', 'enqueued') && !wp_script_is('fluent_mail_admin_app_boot', 'registered')) {
            return;
        }

        $logo = esc_url(FLUENTSMTP_POSTAL_URL . 'assets/images/postal.svg');

        $descriptor = wp_json_encode([
            'key'      => Bootstrap::PROVIDER_KEY,
            'title'    => 'Postal',
            'image'    => $logo,
            'provider' => Bootstrap::PROVIDER_KEY,
        ]);

        $settingsUrl = esc_url(admin_url('options-general.php?page=' . self::MENU_SLUG));

        $script = <<<JS
(function () {
    try {
        if (window.FluentMailAdmin && window.FluentMailAdmin.settings) {
            window.FluentMailAdmin.settings.providers = window.FluentMailAdmin.settings.providers || {};
            if (!window.FluentMailAdmin.settings.providers.postal) {
                window.FluentMailAdmin.settings.providers.postal = {$descriptor};
            }
            window.FluentSmtpPostalSetupUrl = "{$settingsUrl}";
        }
    } catch (e) {}
})();
JS;

        wp_add_inline_script('fluent_mail_admin_app_boot', $script, 'after');
    }
}
