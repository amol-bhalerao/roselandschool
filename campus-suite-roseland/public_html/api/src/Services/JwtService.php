<?php

declare(strict_types=1);

namespace BlogApi\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class JwtService
{
    public function __construct(
        private string $secret,
        private int $ttlSeconds
    ) {
    }

    public function issue(int $userId, string $email, string $role): string
    {
        $now = time();
        $payload = [
            'iat' => $now,
            'exp' => $now + $this->ttlSeconds,
            'sub' => $userId,
            'email' => $email,
            'role' => $role,
        ];
        return JWT::encode($payload, $this->secret, 'HS256');
    }

    /** @return array{sub:int,email:string,role:string} */
    public function decode(string $token): array
    {
        $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
        $arr = (array) $decoded;
        return [
            'sub' => (int) $arr['sub'],
            'email' => (string) $arr['email'],
            'role' => (string) $arr['role'],
        ];
    }
}
