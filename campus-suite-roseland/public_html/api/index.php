<?php

declare(strict_types=1);

use BlogApi\AppFactory;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory as SlimAppFactory;

require __DIR__ . '/vendor/autoload.php';

$root = __DIR__;
if (is_readable($root . '/.env')) {
    \Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$containerBuilder = new ContainerBuilder();
$containerBuilder->useAutowiring(true);
$containerBuilder->addDefinitions($root . '/src/container.php');
$container = $containerBuilder->build();

SlimAppFactory::setContainer($container);
$app = SlimAppFactory::createFromContainer($container);

$configured = trim((string) ($_ENV['APP_BASE_PATH'] ?? ''), '/');
if ($configured !== '') {
    $app->setBasePath('/' . $configured);
} elseif (filter_var($_ENV['APP_AUTO_BASE_PATH'] ?? '0', FILTER_VALIDATE_BOOLEAN)) {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = $scriptName !== '' ? str_replace('\\', '/', dirname($scriptName)) : '';
    if ($dir !== '' && $dir !== '/' && $dir !== '.') {
        $app->setBasePath(rtrim($dir, '/'));
    }
}

AppFactory::register($app);

$app->run();
