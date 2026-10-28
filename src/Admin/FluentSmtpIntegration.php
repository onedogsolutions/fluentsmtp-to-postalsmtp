<?php

namespace FluentSmtpPostal\Admin;

use FluentSmtpPostal\Bootstrap;
use FluentSmtpPostal\Support\ConnectionStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Puts Postal into FluentSMTP's own admin screen.
 *
 * There is no separate settings page: Postal connections are added and edited
 * in FluentSMTP → Connections like any built-in provider. Two pieces make that
 * work, both scoped to FluentSMTP's page by the action they hang off:
 *
 *   - the provider descriptor, merged into the providers map FluentSMTP's app
 *     reads, so Postal is in the picker, the list and the details view;
 *   - postal-fluentsmtp.js, which registers the Postal form as a component on
 *     FluentSMTP's Vue app (see resources/admin/fluentsmtp.js).
 *
 * Saving goes through FluentSMTP's own Save button; SaveInterceptor handles the
 * request when the connection is a Postal one.
 */
class FluentSmtpIntegration
{
    const SCRIPT_HANDLE = 'fluentsmtp-postal-fluentsmtp';

    /**
     * FluentSMTP's boot script, which carries window.FluentMailAdmin.
     */
    const BOOT_HANDLE = 'fluent_mail_admin_app_boot';

    /**
     * The first FluentSMTP release whose admin app can host the Postal form.
     */
    const MIN_FLUENTSMTP = '2.4.0';

    /**
     * Per-user meta written by 1.0.x's "configured on their own screen" notice.
     */
    const LEGACY_NOTICE_META = 'fluentsmtp_postal_handoff_notice_dismissed';

    const VERSION_OPTION = 'fluentsmtp_postal_version';

    public function register()
    {
        // Fired by FluentSMTP after its boot script is enqueued and before its
        // app bundle is, and only on its own screen.
        add_action('fluent_mail_loading_app', [$this, 'enqueue']);

        add_action('admin_init', [$this, 'maybeUpgrade']);

        add_filter(
            'plugin_action_links_' . plugin_basename(FLUENTSMTP_POSTAL_FILE),
            [$this, 'addActionLinks']
        );
    }

    public function enqueue()
    {
        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            FLUENTSMTP_POSTAL_URL . 'assets/admin/js/postal-fluentsmtp.js',
            [self::BOOT_HANDLE],
            FLUENTSMTP_POSTAL_VERSION,
            true
        );

        wp_add_inline_script(self::SCRIPT_HANDLE, $this->descriptorScript(), 'before');
    }

    /**
     * Merge the Postal descriptor into FluentSMTP's providers map.
     *
     * The descriptor's shape is FluentSMTP's contract (its config.php), and its
     * app reads more from it than a key, title and logo:
     *
     *   - options, because picking a provider does
     *     JSON.parse(JSON.stringify(providers[key].options)), and the edit screen
     *     merges them under a stored connection as defaults.
     *   - note, printed as HTML under the form.
     *   - supports_additional_senders, which the connection list reads before
     *     offering its sender dialog. The sender endpoints are not wired for
     *     Postal yet, so it stays false rather than offering a dialog that
     *     cannot save.
     *
     * @return string
     */
    protected function descriptorScript()
    {
        $descriptor = [
            'key'                         => Bootstrap::PROVIDER_KEY,
            'title'                       => 'Postal',
            'image'                       => esc_url(FLUENTSMTP_POSTAL_URL . 'assets/images/postal.svg'),
            'provider'                    => Bootstrap::PROVIDER_KEY,
            'options'                     => ConnectionStore::defaults(),
            'supports_additional_senders' => false,
            'note'                        => $this->providerNote(),
        ];

        $json = wp_json_encode($descriptor, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return <<<JS
(function () {
    try {
        var descriptor = {$json};
        var admin = window.FluentMailAdmin;
        if (admin && admin.settings) {
            admin.settings.providers = admin.settings.providers || {};
            if (!admin.settings.providers[descriptor.key]) {
                admin.settings.providers[descriptor.key] = descriptor;
            }
        }
    } catch (e) {}
})();
JS;
    }

    /**
     * The line FluentSMTP prints under the Postal form.
     *
     * @return string
     */
    protected function providerNote()
    {
        if (!$this->fluentSmtpCanHostForm()) {
            return sprintf(
                '<strong>%s</strong> %s',
                esc_html__('Update FluentSMTP to edit Postal connections.', 'fluentsmtp-postal'),
                esc_html(sprintf(
                    /* translators: %s: minimum FluentSMTP version */
                    __('The Postal form needs FluentSMTP %s or later. Existing Postal connections keep sending.', 'fluentsmtp-postal'),
                    self::MIN_FLUENTSMTP
                ))
            );
        }

        return sprintf(
            '<a href="%s" target="_blank" rel="noopener">%s</a>%s',
            esc_url('https://docs.postalserver.io/developer/api'),
            esc_html__('Read the Postal documentation', 'fluentsmtp-postal'),
            esc_html__(' for how to create an API credential for your mail server.', 'fluentsmtp-postal')
        );
    }

    /**
     * @return bool
     */
    protected function fluentSmtpCanHostForm()
    {
        if (!defined('FLUENTMAIL_PLUGIN_VERSION')) {
            return false;
        }

        return version_compare(FLUENTMAIL_PLUGIN_VERSION, self::MIN_FLUENTSMTP, '>=');
    }

    /**
     * The plugin's "Settings" link opens FluentSMTP's connection list.
     *
     * @param array<int,string> $links
     * @return array<int,string>
     */
    public function addActionLinks($links)
    {
        array_unshift($links, sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('options-general.php?page=fluent-mail#/connections')),
            esc_html__('Settings', 'fluentsmtp-postal')
        ));

        return $links;
    }

    /**
     * One-off housekeeping after an update.
     *
     * 1.0.x stored a per-user flag for a notice that no longer exists.
     */
    public function maybeUpgrade()
    {
        if (get_option(self::VERSION_OPTION) === FLUENTSMTP_POSTAL_VERSION) {
            return;
        }

        delete_metadata('user', 0, self::LEGACY_NOTICE_META, '', true);

        update_option(self::VERSION_OPTION, FLUENTSMTP_POSTAL_VERSION);
    }
}
