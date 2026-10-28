<?php
/**
 * Minimal WordPress stub harness: proves the plugin shipped in the zip parses,
 * autoloads every class it defines, and boots in both dependency states.
 *
 * Live-fire check for bin/package.sh output, not part of the shipped plugin.
 *
 * Usage: php bin/smoke-test.php <plugin-dir> [with-fluentsmtp]
 */

$pluginDir = rtrim($argv[1], '/') . '/';
$withFluent = ($argv[2] ?? '') === 'with-fluentsmtp';

define('ABSPATH', $pluginDir);

$GLOBALS['hooks'] = [];
function add_action($tag, $cb, $priority = 10, $args = 1)
{
    $GLOBALS['hooks'][$tag][] = $cb;
    return true;
}
function add_filter($tag, $cb, $priority = 10, $args = 1) { return true; }
function did_action($tag) { return 0; }
function is_admin() { return true; }
function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f) { return 'http://example.test/wp-content/plugins/' . basename(dirname($f)) . '/'; }
function plugin_basename($f) { return basename(dirname($f)) . '/' . basename($f); }
function load_plugin_textdomain($d, $r = false, $p = '') { return true; }
function current_user_can($c) { return true; }
function add_submenu_page(...$a) { return null; }
function wp_enqueue_script(...$a) { return null; }
function wp_enqueue_style(...$a) { return null; }
function wp_localize_script(...$a) { return null; }
function admin_url($p = '') { return 'http://example.test/wp-admin/' . $p; }
function wp_create_nonce($a) { return 'nonce'; }
function wp_get_current_user() { return (object) ['user_email' => 'a@example.test']; }
function wp_json_encode($d) { return json_encode($d); }
function esc_url($u) { return $u; }
function esc_html__($s, $d = '') { return $s; }
function __($s, $d = '') { return $s; }
function wp_unslash($v) { return $v; }
function sanitize_text_field($v) { return trim((string) $v); }
function sanitize_email($v) { return trim((string) $v); }
function is_email($v) { return (bool) filter_var($v, FILTER_VALIDATE_EMAIL); }
function esc_url_raw($v) { return $v; }
function untrailingslashit($v) { return rtrim($v, '/\\'); }
function wp_specialchars_decode($v, $q = 0) { return $v; }

// Activation/deactivation hooks are registered at file scope, so the harness
// has to accept them before the plugin is required. They are recorded rather
// than run: the lifecycle needs FluentSMTP's settings API, which a stub cannot
// meaningfully provide.
function register_activation_hook($file, $cb)
{
    $GLOBALS['hooks']['activate'][] = $cb;
    return true;
}
function register_deactivation_hook($file, $cb)
{
    $GLOBALS['hooks']['deactivate'][] = $cb;
    return true;
}

// Only reached when the lifecycle decides FluentSMTP is usable, which the
// stubs below never allow - but a missing function would be a fatal rather
// than a skip, so keep them defined.
$GLOBALS['options'] = [];
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value) { $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }

if ($withFluent) {
    define('FLUENTMAIL', 'fluentmail');

    // FluentSMTP's own classes the provider extends.
    require __DIR__ . '/stubs-fluentsmtp.php';

    function fluentMailGetSettings($defaults = [], $cached = true)
    {
        return $GLOBALS['fluent_settings'] ?? [];
    }

    function fluentMail()
    {
        return new class {
            public $bound = [];
            public function singleton($abstract, $concrete = null) { $this->bound[$abstract] = $concrete; }
            public function alias($abstract, $alias) { $this->bound['alias:' . $alias] = $abstract; }
        };
    }
}

require $pluginDir . 'fluentsmtp-postal.php';

foreach ($GLOBALS['hooks']['plugins_loaded'] ?? [] as $cb) {
    $cb();
}

echo 'mode: ' . ($withFluent ? 'FluentSMTP active' : 'FluentSMTP missing') . "\n";
echo 'version: ' . FLUENTSMTP_POSTAL_VERSION . "\n";

$classes = [
    'FluentSmtpPostal\Bootstrap',
    'FluentSmtpPostal\ProviderRegistrar',
    'FluentSmtpPostal\Lifecycle',
    'FluentSmtpPostal\Admin\FluentSmtpIntegration',
    'FluentSmtpPostal\Admin\SaveInterceptor',
    'FluentSmtpPostal\Support\ConnectionStore',
    'FluentSmtpPostal\Support\Crypto',
];

// The provider subclasses FluentSMTP's BaseHandler, so it can only be declared
// when FluentSMTP's classes exist — which is exactly the situation the plugin's
// own dependency check guards for.
if ($withFluent) {
    $classes[] = 'FluentMail\App\Services\Mailer\Providers\Postal\Handler';
    $classes[] = 'FluentMail\App\Services\Mailer\Providers\Postal\ValidatorTrait';
}

$assets = [
    'assets/admin/js/postal-fluentsmtp.js',
    'assets/images/postal.svg',
];

$failed = 0;
echo "classes:\n";
foreach ($classes as $class) {
    $ok = class_exists($class) || trait_exists($class);
    echo '  ' . ($ok ? 'ok  ' : 'FAIL') . ' ' . $class . "\n";
    $failed += $ok ? 0 : 1;
}

echo "assets:\n";
foreach ($assets as $asset) {
    $ok = is_readable($pluginDir . $asset);
    echo '  ' . ($ok ? 'ok  ' : 'FAIL') . ' ' . $asset . "\n";
    $failed += $ok ? 0 : 1;
}

echo "hooks:\n";
foreach (['plugins_loaded', 'fluentMail_loaded', 'admin_notices', 'fluent_mail_loading_app', 'wp_ajax_fluentmail-post-settings', 'activate', 'deactivate'] as $tag) {
    $count = count($GLOBALS['hooks'][$tag] ?? []);
    echo "  $tag: $count\n";
}

/**
 * @param bool   $ok
 * @param string $label
 */
function smoke_check($ok, $label)
{
    echo '  ' . ($ok ? 'ok  ' : 'FAIL') . ' ' . $label . "\n";
    return $ok ? 0 : 1;
}

echo "integration:\n";

// Postal is configured inside FluentSMTP's own screen: no page of its own.
$failed += smoke_check(empty($GLOBALS['hooks']['admin_menu']), 'no admin menu page is registered');

if ($withFluent) {
    $failed += smoke_check(
        count($GLOBALS['hooks']['fluent_mail_loading_app'] ?? []) === 1,
        'form script is enqueued from FluentSMTP\'s app loader'
    );
    $failed += smoke_check(
        count($GLOBALS['hooks']['wp_ajax_fluentmail-post-settings'] ?? []) === 1,
        'save interceptor is on FluentSMTP\'s settings action'
    );

    $interceptor = new FluentSmtpPostal\Admin\SaveInterceptor();
    $call = function ($method, ...$args) use ($interceptor) {
        $ref = new ReflectionMethod($interceptor, $method);
        if (PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        return $ref->invokeArgs($interceptor, $args);
    };

    // Another provider's save must fall through to FluentSMTP untouched: if
    // the interceptor answered it, wp_send_json_* would end this script here.
    $_POST = ['connection' => ['provider' => 'smtp', 'sender_email' => 'a@example.test'], 'connection_key' => '0'];
    $interceptor->maybeHandle();
    $failed += smoke_check(true, 'non-Postal saves are left to FluentSMTP');

    $failed += smoke_check($call('editingKey') === null, "connection_key '0' means a new connection");

    $mask = '__MASKED_KEY_ENCRYPTED__';
    $GLOBALS['fluent_settings'] = [
        'connections' => [
            'postalkey' => ['provider_settings' => ['provider' => 'postal', 'api_key' => 'stored-postal-key']],
            'smtpkey'   => ['provider_settings' => ['provider' => 'sendgrid', 'api_key' => 'sendgrid-key']],
        ],
    ];

    $connection = $call('sanitizeConnection', ['api_key' => $mask, 'server_url' => 'https://postal.example.test/']);
    $failed += smoke_check($connection['server_url'] === 'https://postal.example.test', 'server URL is sanitized');

    $resolved = $call('resolveMaskedKey', $connection, 'postalkey');
    $failed += smoke_check($resolved['api_key'] === 'stored-postal-key', 'masked key resolves to the stored Postal key');

    $resolved = $call('resolveMaskedKey', $connection, 'smtpkey');
    $failed += smoke_check($resolved['api_key'] === '', "a switched connection does not inherit another provider's key");

    $resolved = $call('resolveMaskedKey', $connection, null);
    $failed += smoke_check($resolved['api_key'] === '', 'a mask on a new connection is not a key');

    $connection['api_key'] = 'typed-key';
    $resolved = $call('resolveMaskedKey', $connection, 'postalkey');
    $failed += smoke_check($resolved['api_key'] === 'typed-key', 'a typed key replaces the stored one');

    $masked = $call('maskConnections', $GLOBALS['fluent_settings']['connections']);
    $failed += smoke_check(
        $masked['postalkey']['provider_settings']['api_key'] === $mask && $masked['smtpkey']['provider_settings']['api_key'] === $mask,
        'connections are returned to the browser masked'
    );
}

echo $failed === 0 ? "SMOKE OK\n" : "SMOKE FAILED ($failed)\n";
exit($failed === 0 ? 0 : 1);
