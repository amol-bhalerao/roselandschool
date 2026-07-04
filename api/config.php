<?php

declare(strict_types=1);

const DB_HOST = 'localhost';
const DB_NAME = 'u441114691_roseland';
const DB_USER = 'u441114691_roseland';
const DB_PASS = 'Roseland@1234567890';
const ADMIN_USERNAME = 'admin';
const ADMIN_PASSWORD = 'Roseland@1234567890';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function start_admin_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true,
        ]);
    }
}

function require_admin(): void
{
    start_admin_session();

    if (empty($_SESSION['admin_logged_in'])) {
        json_response(['message' => 'Please login to continue.'], 401);
    }
}
