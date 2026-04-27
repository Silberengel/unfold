<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root.'/.env')) {
    (new Dotenv())->bootEnv($root.'/.env');
}

// Defaults when .env is missing (e.g. CI before env is wired).
if (!isset($_ENV['APP_ENV'], $_SERVER['APP_ENV'])) {
    $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'dev';
}
if (!isset($_ENV['APP_SECRET']) && !isset($_SERVER['APP_SECRET'])) {
    $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = 'phpstan_bootstrap_not_for_prod';
}
if (!isset($_SERVER['DATABASE_URL']) && !isset($_ENV['DATABASE_URL'])) {
    $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:////tmp/unfold_phpstan_meta.sqlite';
}

$kernel = new Kernel('dev', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
