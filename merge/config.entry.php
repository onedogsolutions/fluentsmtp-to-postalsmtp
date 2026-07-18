<?php
/**
 * Add this entry to the 'providers' array in
 * app/Services/Mailer/Providers/config.php
 *
 * Place it alongside the other API providers (e.g. after 'postmark').
 */

'postal' => [
    'key'      => 'postal',
    'title'    => __('Postal', 'fluent-smtp'),
    'image'    => fluentMailAssetUrl('images/provider-postal.svg'),
    'provider' => 'Postal',
    'options'  => [
        'sender_name'        => '',
        'sender_email'       => '',
        'force_from_name'    => 'no',
        'force_from_email'   => 'yes',
        'return_path'        => 'yes',
        'server_url'         => '',
        'api_key'            => '',
        'key_store'          => 'db',
        'verify_ssl'         => 'yes',
        'allow_insecure_url' => 'no',
        'bounce'             => 'no',
        'additional_senders' => [],
    ],
    'note'     => '<a href="https://docs.postalserver.io/" target="_blank" rel="noopener">' . __('Read the documentation', 'fluent-smtp') . '</a>' . __(' for how to create a Postal API credential.', 'fluent-smtp'),
],
