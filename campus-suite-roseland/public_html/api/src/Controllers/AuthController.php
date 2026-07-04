<?php

declare(strict_types=1);

namespace BlogApi\Controllers;

use BlogApi\Database;
use BlogApi\Services\JwtService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuthController
{
    public function __construct(
        private Database $db,
        private JwtService $jwt
    ) {
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($email === '' || $password === '') {
            return $this->json($response, ['error' => 'Email and password required'], 422);
        }

        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT id, email, password_hash, role, display_name FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $user = $st->fetch();
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return $this->json($response, ['error' => 'Invalid credentials'], 401);
        }
        if (($user['role'] ?? '') === 'invite_pending') {
            return $this->json($response, ['error' => 'Please accept your invite and set a password first'], 403);
        }

        $token = $this->jwt->issue((int) $user['id'], (string) $user['email'], (string) $user['role']);
        return $this->json($response, [
            'token' => $token,
            'user' => [
                'id' => (int) $user['id'],
                'email' => (string) $user['email'],
                'role' => (string) $user['role'],
                'display_name' => isset($user['display_name']) && $user['display_name'] !== null && $user['display_name'] !== ''
                    ? (string) $user['display_name']
                    : null,
            ],
        ]);
    }

    public function acceptInvite(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $token = trim((string) ($body['token'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($token === '' || strlen($password) < 10) {
            return $this->json($response, ['error' => 'Invite token and password of at least 10 characters are required'], 422);
        }

        $hash = hash('sha256', $token);
        $pdo = $this->db->pdo();
        $st = $pdo->prepare(
            "SELECT id, email, display_name, role FROM users
             WHERE invite_token_hash = ?
               AND invite_accepted_at IS NULL
               AND invite_expires_at > NOW()
             LIMIT 1"
        );
        $st->execute([$hash]);
        $user = $st->fetch();
        if (!$user) {
            return $this->json($response, ['error' => 'Invite link is invalid or expired'], 404);
        }

        $role = (string) $user['role'];
        if ($role === 'invite_pending') {
            $role = 'clerk';
        }
        $pdo->prepare(
            'UPDATE users SET password_hash = ?, role = ?, invite_token_hash = NULL, invite_accepted_at = NOW() WHERE id = ?'
        )->execute([password_hash($password, PASSWORD_DEFAULT), $role, (int) $user['id']]);

        $token = $this->jwt->issue((int) $user['id'], (string) $user['email'], $role);
        return $this->json($response, [
            'token' => $token,
            'user' => [
                'id' => (int) $user['id'],
                'email' => (string) $user['email'],
                'role' => $role,
                'display_name' => $user['display_name'] ?: null,
            ],
        ]);
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
