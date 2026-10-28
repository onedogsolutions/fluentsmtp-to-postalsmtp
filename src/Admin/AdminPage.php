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

    /**
     * Where FluentSMTP renders its own screens. Used to scope every hook below
     * that must not touch any other admin page.
     */
    const FLUENTSMTP_HOOK = 'settings_page_fluent-mail';

    /**
     * Per-user meta that hides the hand-off notice once it has been acted on.
     */
    const NOTICE_META = 'fluentsmtp_postal_handoff_notice_dismissed';

    /**
     * Whether the provider descriptor has already been added for this request.
     * The injection runs twice on purpose (see register()), and a second
     * wp_add_inline_script() would print the same script twice.
     *
     * @var bool
     */
    protected $injected = false;

    public function register()
    {
        add_action('admin_menu', [$this, 'addMenu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);

        // Make Postal connections render with a logo/title inside FluentSMTP's
        // own connection list by injecting the provider metadata its Vue app
        // reads. This runs on FluentSMTP's page only and never mutates settings.
        //
        // Two entry points, because the order is FluentSMTP's to change: if it
        // registers its boot script from an admin_enqueue_scripts callback that
        // runs after ours, the first attempt finds no handle to attach to. The
        // second runs on admin_print_scripts at priority 5 - after every enqueue
        // callback has had its turn, but before core's wp_print_scripts (hooked
        // to the same action at priority 10) actually prints. Whichever runs
        // first wins; the other sees $injected.
        add_action('admin_enqueue_scripts', [$this, 'injectProviderIntoFluentSmtp'], 30);
        add_action('admin_print_scripts', [$this, 'injectProviderIntoFluentSmtp'], 5);

        // FluentSMTP's connection wizard cannot render a third-party provider's
        // fields (it resolves <component :is="connection.provider"> against a
        // component map compiled into its own bundle), so the credentials form
        // lives on our screen. Point at it from FluentSMTP's page instead of
        // letting an admin hunt through Settings.
        add_action('admin_notices', [$this, 'renderHandoffNotice']);
        add_action('admin_init', [$this, 'maybeDismissNotice']);

        add_filter(
            'plugin_action_links_' . plugin_basename(FLUENTSMTP_POSTAL_FILE),
            [$this, 'addActionLinks']
        );
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

    /**
     * Add a Settings link on the Plugins screen, next to Activate/Deactivate.
     *
     * @param array<int,string> $links
     * @return array<int,string>
     */
    public function addActionLinks($links)
    {
        $settings = sprintf(
            '<a href="%s">%s</a>',
            esc_url($this->settingsUrl()),
            esc_html__('Settings', 'fluentsmtp-postal')
        );

        array_unshift($links, $settings);

        return $links;
    }

    /**
     * @return string
     */
    protected function settingsUrl()
    {
        return admin_url('options-general.php?page=' . self::MENU_SLUG);
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
            'settings_url'     => $this->settingsUrl(),
            'user_email'       => $user->user_email,
            'crypto_available' => Crypto::isAvailable(),
            'wp_config_const'  => 'FLUENTSMTP_POSTAL_API_KEY',
            'defaults'         => ConnectionStore::defaults(),
            'connections'      => $store->getPostalConnections(),
            'trans'            => [],
        ]);
    }

    /**
     * Inject the Postal provider descriptor into FluentSMTP's admin app.
     *
     * Three things, all read by FluentSMTP's own Vue app: our connections show a
     * logo and title in its list, its wizard can switch to Postal without
     * throwing, and the wizard prints a note saying where Postal's credentials
     * are actually entered. Defensive throughout - it runs only when FluentSMTP's
     * boot script is present, and it mutates nothing but a JS object.
     *
     * @param string|null $hook The admin page hook, when the calling action passes one.
     */
    public function injectProviderIntoFluentSmtp($hook = null)
    {
        if ($this->injected) {
            return;
        }

        if ($hook === null) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            $hook   = $screen ? $screen->id : '';
        }

        if ($hook !== self::FLUENTSMTP_HOOK) {
            return;
        }

        if (!wp_script_is('fluent_mail_admin_app_boot', 'registered')) {
            return;
        }

        // The descriptor's shape is FluentSMTP's contract, not ours, and the
        // admin app reads three things from it that a key/title/image entry does
        // not carry:
        //
        //   - options, because switching to a provider does
        //     JSON.parse(JSON.stringify(providers[key].options)). Without it that
        //     throws and the rest of the switch never runs. They are also the
        //     defaults the edit screen merges under a stored connection.
        //   - note, rendered as HTML under the wizard. It is how an admin learns
        //     that Postal has no username/password and where the form does live.
        //   - supports_additional_senders, which the connections list reads to
        //     decide whether to offer its sender dialog. Both this and the
        //     sender-management endpoints are still TODO for Postal, so it stays
        //     false rather than showing a dialog that cannot save.
        $descriptor = [
            'key'                         => Bootstrap::PROVIDER_KEY,
            'title'                       => 'Postal',
            'image'                       => esc_url(FLUENTSMTP_POSTAL_URL . 'assets/images/postal.svg'),
            'provider'                    => Bootstrap::PROVIDER_KEY,
            'options'                     => ConnectionStore::defaults(),
            'supports_additional_senders' => false,
            'note'                        => $this->providerNote(),
        ];

        $json = wp_json_encode(
            $descriptor,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        $settingsUrl = wp_json_encode($this->settingsUrl(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $script = <<<JS
(function () {
    try {
        var descriptor = {$json};
        if (window.FluentMailAdmin && window.FluentMailAdmin.settings) {
            window.FluentMailAdmin.settings.providers = window.FluentMailAdmin.settings.providers || {};
            if (!window.FluentMailAdmin.settings.providers[descriptor.key]) {
                window.FluentMailAdmin.settings.providers[descriptor.key] = descriptor;
            }
            window.FluentSmtpPostalSetupUrl = {$settingsUrl};
        }
    } catch (e) {}
})();
JS;

        if (wp_add_inline_script('fluent_mail_admin_app_boot', $script, 'after') !== false) {
            $this->injected = true;
        }
    }

    /**
     * The one-line explanation FluentSMTP's wizard prints under a Postal
     * connection, with the way to the screen that owns the credentials.
     *
     * @return string
     */
    protected function providerNote()
    {
        return sprintf(
            '<strong>%s</strong> %s <a href="%s">%s</a>',
            esc_html__('Postal has no SMTP username or password:', 'fluentsmtp-postal'),
            esc_html__('it authenticates with a server API key, set together with the server URL on the Postal settings screen.', 'fluentsmtp-postal'),
            esc_url($this->settingsUrl()),
            esc_html__('Open Postal settings →', 'fluentsmtp-postal')
        );
    }

    /**
     * Notice on FluentSMTP's screens pointing at the Postal connection form.
     *
     * Only shown to someone who can act on it, only on FluentSMTP's page, and
     * only until it is dismissed - it is a signpost, not a billboard.
     */
    public function renderHandoffNotice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->id !== self::FLUENTSMTP_HOOK) {
            return;
        }

        if (get_user_meta(get_current_user_id(), self::NOTICE_META, true)) {
            return;
        }

        $dismiss = wp_nonce_url(
            add_query_arg('fluentsmtp_postal_dismiss_notice', '1', $this->settingsUrl()),
            'fluentsmtp_postal_dismiss_notice'
        );

        printf(
            '<div class="notice notice-info"><p><strong>%s</strong> %s</p><p><a href="%s" class="button button-primary">%s</a> <a href="%s" class="button">%s</a></p></div>',
            esc_html__('Postal connections are configured on their own screen.', 'fluentsmtp-postal'),
            esc_html__('FluentSMTP’s connection form has no fields for a Postal server URL or API key, so Postal connections are added, tested and edited under Settings → Postal (FluentSMTP). They still appear in this list and are used for matching From addresses.', 'fluentsmtp-postal'),
            esc_url($this->settingsUrl()),
            esc_html__('Open Postal settings', 'fluentsmtp-postal'),
            esc_url($dismiss),
            esc_html__('Dismiss', 'fluentsmtp-postal')
        );
    }

    /**
     * Remember a dismissed hand-off notice for the current user.
     */
    public function maybeDismissNotice()
    {
        if (empty($_GET['fluentsmtp_postal_dismiss_notice'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('fluentsmtp_postal_dismiss_notice');

        update_user_meta(get_current_user_id(), self::NOTICE_META, 'yes');

        wp_safe_redirect($this->settingsUrl());
        exit;
    }
}
