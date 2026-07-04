<?php

declare(strict_types=1);

namespace BlogApi\Controllers;

use BlogApi\Database;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ProfileController
{
    public function __construct(private Database $db)
    {
    }

    public function me(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = (int) $request->getAttribute('user_id');
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT id, email, display_name, role, created_at FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }
        $row['id'] = (int) $row['id'];
        $response->getBody()->write(json_encode($row, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function updateProfile(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = (int) $request->getAttribute('user_id');
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $pdo = $this->db->pdo();

        $fields = [];
        $bind = [];

        if (array_key_exists('display_name', $body)) {
            $fields[] = 'display_name = ?';
            $dn = trim((string) $body['display_name']);
            $bind[] = $dn !== '' ? $dn : null;
        }

        if (array_key_exists('email', $body)) {
            $email = trim((string) $body['email']);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->json($response, ['error' => 'Valid email required'], 422);
            }
            $check = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $check->execute([$email, $userId]);
            if ($check->fetch()) {
                return $this->json($response, ['error' => 'Email already in use'], 422);
            }
            $fields[] = 'email = ?';
            $bind[] = $email;
        }

        if ($fields === []) {
            return $this->me($request, $response);
        }

        $bind[] = $userId;
        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $pdo->prepare($sql)->execute($bind);

        return $this->me($request, $response);
    }

    public function updatePassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = (int) $request->getAttribute('user_id');
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $current = (string) ($body['current_password'] ?? '');
        $new = (string) ($body['new_password'] ?? '');
        if ($current === '' || $new === '') {
            return $this->json($response, ['error' => 'current_password and new_password required'], 422);
        }
        if (strlen($new) < 8) {
            return $this->json($response, ['error' => 'New password must be at least 8 characters'], 422);
        }

        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($current, (string) $row['password_hash'])) {
            return $this->json($response, ['error' => 'Current password is incorrect'], 403);
        }

        $hash = password_hash($new, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);

        return $response->withStatus(204);
    }

    private function json(ResponseInterface $response, array $data, int $status = 400): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
