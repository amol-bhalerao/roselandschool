<?php

declare(strict_types=1);

require __DIR__ . '/config.php';

start_admin_session();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_response([
        'logged_in' => !empty($_SESSION['admin_logged_in']),
        'username' => $_SESSION['admin_username'] ?? null,
    ]);
}

if ($method === 'POST') {
    $payload = json_decode((string) file_get_contents('php://input'), true);

    if (!is_array($payload)) {
        json_response(['message' => 'Invalid login data.'], 400);
    }

    $username = trim((string) ($payload['username'] ?? ''));
    $password = (string) ($payload['password'] ?? '');

    if ($username === ADMIN_USERNAME && hash_equals(ADMIN_PASSWORD, $password)) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        json_response(['message' => 'Login successful.', 'logged_in' => true]);
    }

    json_response(['message' => 'Invalid username or password.'], 401);
}

if ($method === 'DELETE') {
    $_SESSION = [];
    session_destroy();
    json_response(['message' => 'Logged out successfully.', 'logged_in' => false]);
}

json_response(['message' => 'Method not allowed.'], 405);
