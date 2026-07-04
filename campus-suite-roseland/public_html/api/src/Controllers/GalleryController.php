<?php

declare(strict_types=1);

namespace BlogApi\Controllers;

use BlogApi\Database;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class GalleryController
{
    public function __construct(private Database $db)
    {
    }

    public function listPublic(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $rows = $pdo->query(
            'SELECT id, sort_order, media_type, url, caption
             FROM gallery_items ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['sort_order'] = (int) $r['sort_order'];
        }
        unset($r);
        $response->getBody()->write(json_encode(['data' => $rows], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function listAdmin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $rows = $pdo->query(
            'SELECT id, sort_order, media_type, url, caption
             FROM gallery_items ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['sort_order'] = (int) $r['sort_order'];
        }
        unset($r);
        $response->getBody()->write(json_encode(['data' => $rows], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $mediaType = trim((string) ($body['media_type'] ?? ''));
        $url = trim((string) ($body['url'] ?? ''));
        if ($url === '' || !in_array($mediaType, ['image', 'video'], true)) {
            return $this->json($response, ['error' => 'media_type (image|video) and url are required'], 422);
        }
        $caption = isset($body['caption']) ? trim((string) $body['caption']) : '';
        $sortOrder = (int) ($body['sort_order'] ?? 0);

        $pdo = $this->db->pdo();
        $st = $pdo->prepare(
            'INSERT INTO gallery_items (sort_order, media_type, url, caption) VALUES (?, ?, ?, ?)'
        );
        $st->execute([$sortOrder, $mediaType, $url, $caption !== '' ? $caption : null]);
        $id = (int) $pdo->lastInsertId();
        $response->getBody()->write(json_encode(['id' => $id], JSON_THROW_ON_ERROR));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT id FROM gallery_items WHERE id = ?');
        $st->execute([$id]);
        if (!$st->fetch()) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }

        $fields = [];
        $bind = [];
        foreach (['caption', 'url'] as $k) {
            if (array_key_exists($k, $body)) {
                $fields[] = "$k = ?";
                $bind[] = trim((string) $body[$k]) ?: null;
            }
        }
        if (array_key_exists('sort_order', $body)) {
            $fields[] = 'sort_order = ?';
            $bind[] = (int) $body['sort_order'];
        }
        if (array_key_exists('media_type', $body)) {
            $mt = trim((string) $body['media_type']);
            if (in_array($mt, ['image', 'video'], true)) {
                $fields[] = 'media_type = ?';
                $bind[] = $mt;
            }
        }
        if ($fields !== []) {
            $bind[] = $id;
            $pdo->prepare('UPDATE gallery_items SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($bind);
        }
        return $response->withStatus(204);
    }

    public function reorder(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $ids = $body['ordered_ids'] ?? null;
        if (!is_array($ids) || $ids === []) {
            return $this->json($response, ['error' => 'ordered_ids array required'], 422);
        }
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $ord = 0;
            foreach ($ids as $rawId) {
                $gid = (int) $rawId;
                if ($gid <= 0) {
                    continue;
                }
                $pdo->prepare('UPDATE gallery_items SET sort_order = ? WHERE id = ?')->execute([$ord++, $gid]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return $this->json($response, ['error' => 'Reorder failed'], 500);
        }
        return $response->withStatus(204);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('DELETE FROM gallery_items WHERE id = ?');
        $st->execute([$id]);
        if ($st->rowCount() === 0) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }
        return $response->withStatus(204);
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
