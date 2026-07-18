<?php

namespace FluentSmtpPostal;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the Postal provider into FluentSMTP's IoC container.
 *
 * FluentSMTP resolves a mailer purely through its container: at send time
 * Factory::make($connection['provider']) calls $app->make('postal'). Binding
 * the alias here is all that is required for the send path to work — the static
 * provider catalog (config.php) is only consulted by the admin UI, which this
 * plugin supplies separately.
 *
 * This mirrors FluentSMTP's own app/Bindings.php exactly, so an upstream merge
 * is a one-line addition to that file.
 */
class ProviderRegistrar
{
    /**
     * @param \FluentMail\Includes\Core\Application $app FluentSMTP's container.
     */
    public static function register($app)
    {
        if (!$app || !is_object($app)) {
            return;
        }

        $handlerClass = 'FluentMail\\App\\Services\\Mailer\\Providers\\Postal\\Handler';

        // Guard against a FluentSMTP internal that has moved between versions.
        if (!method_exists($app, 'singleton') || !method_exists($app, 'alias')) {
            return;
        }

        // Load the provider classes explicitly (they live in FluentSMTP's
        // namespace) so resolution never depends on autoloader ordering.
        require_once FLUENTSMTP_POSTAL_DIR . 'src/Providers/Postal/ValidatorTrait.php';
        require_once FLUENTSMTP_POSTAL_DIR . 'src/Providers/Postal/Handler.php';

        $app->alias($handlerClass, Bootstrap::PROVIDER_KEY);
        $app->singleton($handlerClass, function () use ($handlerClass) {
            return new $handlerClass();
        });
    }
}
