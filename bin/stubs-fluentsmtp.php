<?php
/**
 * Stand-ins for the FluentSMTP core classes the Postal provider extends or uses.
 *
 * Only the surface the provider touches is emulated, so the smoke test can prove
 * the shipped provider classes declare correctly against a FluentSMTP-shaped
 * autoloader. Real behaviour still needs a WordPress + FluentSMTP install.
 */

namespace FluentMail\Includes\Support {
    class Arr
    {
        public static function get($array, $key, $default = null)
        {
            if (!is_array($array)) {
                return $default;
            }
            if (array_key_exists($key, $array)) {
                return $array[$key];
            }
            return $default;
        }

        public static function set(&$array, $key, $value)
        {
            $array[$key] = $value;
            return $array;
        }
    }
}

namespace FluentMail\App\Services\Mailer {
    abstract class BaseHandler
    {
        public $settings = [];

        public function __construct($app = null, $manager = null) {}

        public function getSetting($key, $default = null)
        {
            return $this->settings[$key] ?? $default;
        }

        abstract public function send();
    }

    trait ValidatorTrait
    {
        public function validateProviderInformation($connection) {}
    }
}
