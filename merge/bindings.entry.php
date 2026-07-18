<?php
/**
 * Add this key to the $singletons array in app/Bindings.php
 * (alongside 'postmark', 'sendgrid', etc.).
 */

'postal' => 'FluentMail\App\Services\Mailer\Providers\Postal\Handler',
