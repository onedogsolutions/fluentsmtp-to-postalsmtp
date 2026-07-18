<?php

namespace FluentSmtpPostal\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Encrypts the Postal API key at rest.
 *
 * FluentSMTP encrypts provider secrets, but its provider->field map is
 * hard-coded and does not include "postal", so it neither encrypts nor decrypts
 * our key. This plugin therefore manages the key's encryption itself, reusing
 * FluentSMTP's own AES-256-CTR primitive (keyed off the WP salts) so the
 * behaviour is identical to the core providers.
 *
 * A short marker prefix makes decrypt() idempotent: it returns already-plaintext
 * input unchanged, which keeps the "test connection" path (un-stored key) and
 * the live-send path (stored, encrypted key) working through one code path.
 */
class Crypto
{
    const MARKER = 'postalenc::';

    /**
     * @param string $plain
     * @return string Ciphertext with marker, or plaintext if encryption is unavailable.
     */
    public static function encrypt($plain)
    {
        if ($plain === '' || $plain === null) {
            return $plain;
        }

        // Never double-encrypt.
        if (self::isEncrypted($plain)) {
            return $plain;
        }

        $cipher = self::primitive($plain, 'e');

        // Encryption unavailable (no openssl) or failed: store plaintext. The
        // admin UI warns the user when this happens.
        if ($cipher === false || $cipher === $plain) {
            return $plain;
        }

        return self::MARKER . $cipher;
    }

    /**
     * @param string $stored
     * @return string Plaintext.
     */
    public static function decrypt($stored)
    {
        if ($stored === '' || $stored === null) {
            return (string) $stored;
        }

        if (!self::isEncrypted($stored)) {
            return $stored; // already plaintext
        }

        $cipher = substr($stored, strlen(self::MARKER));
        $plain  = self::primitive($cipher, 'd');

        return $plain === false ? '' : $plain;
    }

    public static function isEncrypted($value)
    {
        return is_string($value) && strpos($value, self::MARKER) === 0;
    }

    /**
     * Whether secrets will actually be encrypted on this install.
     */
    public static function isAvailable()
    {
        return extension_loaded('openssl');
    }

    /**
     * @param string $value
     * @param string $type 'e' to encrypt, 'd' to decrypt.
     * @return string|false
     */
    protected static function primitive($value, $type)
    {
        if (function_exists('fluentMailEncryptDecrypt')) {
            return fluentMailEncryptDecrypt($value, $type);
        }

        // Standalone fallback mirroring FluentSMTP's implementation, in case the
        // helper is unavailable for any reason.
        if (!extension_loaded('openssl')) {
            return $value;
        }

        $salt = (defined('LOGGED_IN_SALT') && '' !== LOGGED_IN_SALT) ? LOGGED_IN_SALT : 'fluentsmtp-postal-fallback-salt';
        $key  = (defined('LOGGED_IN_KEY') && '' !== LOGGED_IN_KEY) ? LOGGED_IN_KEY : 'fluentsmtp-postal-fallback-key';
        $method = 'aes-256-ctr';
        $ivlen  = openssl_cipher_iv_length($method);

        if ($type === 'e') {
            $iv  = openssl_random_pseudo_bytes($ivlen);
            $raw = openssl_encrypt($value . $salt, $method, $key, 0, $iv);
            return $raw === false ? false : base64_encode($iv . $raw);
        }

        $raw = base64_decode($value, true);
        if ($raw === false) {
            return false;
        }
        $iv  = substr($raw, 0, $ivlen);
        if (strlen($iv) < $ivlen) {
            $iv = str_pad($iv, $ivlen, "\0");
        }
        $decrypted = openssl_decrypt(substr($raw, $ivlen), $method, $key, 0, $iv);
        if ($decrypted === false || substr($decrypted, -strlen($salt)) !== $salt) {
            return false;
        }
        return substr($decrypted, 0, -strlen($salt));
    }
}
