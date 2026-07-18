<?php
/**
 * app/Functions/helpers.php
 *
 * Add 'postal' => 'api_key' to BOTH $providerKeyMaps arrays: one in
 * fluentMailGetSettings() and one in fluentMailSetSettings(). This makes core
 * encrypt/decrypt the Postal API key at rest exactly like the other API
 * providers.
 *
 * Before:
 *   'postmark'    => 'api_key',
 *   'elasticmail' => 'api_key',
 *
 * After:
 *   'postmark'    => 'api_key',
 *   'elasticmail' => 'api_key',
 *   'postal'      => 'api_key',
 *
 * Once core handles this, the companion plugin's self-managed encryption
 * (Support/Crypto.php, and the decrypt call in Handler::setSettings) becomes
 * redundant and should be removed so the key is not double-processed.
 */
