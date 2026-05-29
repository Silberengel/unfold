<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    '@symfony/ux-live-component' => [
        'path' => './vendor/symfony/ux-live-component/assets/dist/live_controller.js',
    ],
    'quill' => [
        'version' => '2.0.3',
    ],
    'lodash-es' => [
        'version' => '4.17.21',
    ],
    'parchment' => [
        'version' => '3.0.0',
    ],
    'quill-delta' => [
        'version' => '5.1.0',
    ],
    'eventemitter3' => [
        'version' => '5.0.1',
    ],
    'fast-diff' => [
        'version' => '1.3.0',
    ],
    'lodash.clonedeep' => [
        'version' => '4.5.0',
    ],
    'lodash.isequal' => [
        'version' => '4.5.0',
    ],
    'nostr-tools' => [
        'version' => '2.10.4',
    ],
    'nostr-tools/nip19' => [
        'version' => '2.10.4',
    ],
    '@noble/hashes' => [
        'version' => '1.3.1',
    ],
    '@noble/hashes/utils' => [
        'version' => '1.3.1',
    ],
    '@noble/hashes/crypto' => [
        'version' => '1.3.1',
    ],
    '@scure/base' => [
        'version' => '2.0.0',
    ],
    'quill/dist/quill.core.css' => [
        'version' => '2.0.3',
        'type' => 'css',
    ],
    'quill/dist/quill.snow.css' => [
        'version' => '2.0.3',
        'type' => 'css',
    ],
    'nostr-tools/nip46' => [
        'version' => '2.23.5',
    ],
    '@noble/curves/secp256k1.js' => [
        'version' => '2.0.1',
    ],
    '@noble/hashes/utils.js' => [
        'version' => '2.0.1',
    ],
    '@noble/hashes/sha2.js' => [
        'version' => '2.0.1',
    ],
    '@noble/ciphers/chacha.js' => [
        'version' => '2.1.1',
    ],
    '@noble/ciphers/utils.js' => [
        'version' => '2.1.1',
    ],
    '@noble/hashes/hkdf.js' => [
        'version' => '2.0.1',
    ],
    '@noble/hashes/hmac.js' => [
        'version' => '2.0.1',
    ],
];
