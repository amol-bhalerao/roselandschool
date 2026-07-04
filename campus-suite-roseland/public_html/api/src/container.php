<?php

declare(strict_types=1);

use BlogApi\Database;
use BlogApi\Services\JwtService;
use Psr\Container\ContainerInterface;

return [
    \PDO::class => function (): \PDO {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $_ENV['DB_HOST'] ?? '127.0.0.1',
            $_ENV['DB_PORT'] ?? '3306',
            $_ENV['DB_NAME'] ?? 'blog_mfe'
        );
        $pdo = new \PDO(
            $dsn,
            $_ENV['DB_USER'] ?? 'root',
            $_ENV['DB_PASS'] ?? '',
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );
        return $pdo;
    },
    Database::class => function (ContainerInterface $c): Database {
        return new Database($c->get(\PDO::class));
    },
    JwtService::class => function (): JwtService {
        return new JwtService(
            $_ENV['JWT_SECRET'] ?? 'change-me-in-production-min-32-chars!!',
            (int) ($_ENV['JWT_TTL'] ?? 86400)
        );
    },
    \BlogApi\Middleware\AuthMiddleware::class => function (ContainerInterface $c): \BlogApi\Middleware\AuthMiddleware {
        return new \BlogApi\Middleware\AuthMiddleware($c->get(JwtService::class));
    },
];
