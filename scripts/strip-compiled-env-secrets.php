#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Remove deployment secrets from Symfony's compiled .env.local.php (Docker prod build).
 * Fails the build if dump-env did not produce a readable array env file.
 */

$path = '.env.local.php';

if (!is_readable($path)) {
    fwrite(STDERR, "strip-compiled-env-secrets: {$path} is missing or not readable (did composer dump-env fail?)\n");
    exit(1);
}

$env = include $path;
if (!is_array($env)) {
    fwrite(STDERR, "strip-compiled-env-secrets: {$path} must return an array\n");
    exit(1);
}

$strip = array_flip([
    'APP_SECRET',
    'DATABASE_URL',
    'MYSQL_USER',
    'MYSQL_PASSWORD',
    'MYSQL_ROOT_PASSWORD',
]);

$stripped = array_diff_key($env, $strip);
$out = '<?php return '.var_export($stripped, true).';'.PHP_EOL;

if (file_put_contents($path, $out) === false) {
    fwrite(STDERR, "strip-compiled-env-secrets: failed writing {$path}\n");
    exit(1);
}
