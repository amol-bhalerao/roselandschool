<?php

declare(strict_types=1);

namespace BlogApi\Controllers;

use BlogApi\Database;
use BlogApi\Util\Slug;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class EventController
{
    public function __construct(private Database $db)
    {
    }

    public function listPublic(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $rows = $pdo->query(
            "SELECT id, slug, title, excerpt, published_at, sort_order
             FROM events WHERE status = 'published' AND published_at IS NOT NULL
             ORDER BY sort_order ASC, published_at DESC, id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['sort_order'] = (int) $r['sort_order'];
        }
        unset($r);
        $response->getBody()->write(json_encode(['data' => $rows], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getBySlugPublic(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = trim((string) ($args['slug'] ?? ''));
        if ($slug === '') {
            return $this->json($response, ['error' => 'Not found'], 404);
        }
        $pdo = $this->db->pdo();
        $st = $pdo->prepare(
            "SELECT id, slug, title, excerpt, content_html, published_at, updated_at
             FROM events WHERE slug = ? AND status = 'published' AND published_at IS NOT NULL LIMIT 1"
        );
        $st->execute([$slug]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }
        $row['id'] = (int) $row['id'];
        $response->getBody()->write(json_encode($row, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function listAdmin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $rows = $pdo->query(
            'SELECT id, slug, title, excerpt, status, sort_order, published_at, updated_at FROM events ORDER BY sort_order ASC, id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['sort_order'] = (int) $r['sort_order'];
        }
        unset($r);
        $response->getBody()->write(json_encode(['data' => $rows], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getAdmin(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT * FROM events WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }
        $row['id'] = (int) $row['id'];
        $row['sort_order'] = (int) $row['sort_order'];
        $response->getBody()->write(json_encode($row, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $title = trim((string) ($body['title'] ?? ''));
        $contentHtml = (string) ($body['content_html'] ?? '');
        if ($title === '') {
            return $this->json($response, ['error' => 'Title is required'], 422);
        }
        $pdo = $this->db->pdo();
        $slugRaw = trim((string) ($body['slug'] ?? ''));
        $base = $slugRaw !== '' ? Slug::fromTitle($slugRaw) : Slug::fromTitle($title);
        $slug = Slug::unique($pdo, $base, 'events');
        $excerpt = isset($body['excerpt']) ? trim((string) $body['excerpt']) : '';
        $status = ($body['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        $sortOrder = (int) ($body['sort_order'] ?? 0);
        $publishedAt = null;
        if ($status === 'published') {
            $publishedAt = trim((string) ($body['published_at'] ?? ''));
            if ($publishedAt === '') {
                $publishedAt = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            }
        }

        $st = $pdo->prepare(
            'INSERT INTO events (slug, title, excerpt, content_html, sort_order, status, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $slug,
            $title,
            $excerpt !== '' ? $excerpt : null,
            $contentHtml,
            $sortOrder,
            $status,
            $publishedAt,
        ]);
        $id = (int) $pdo->lastInsertId();
        $st = $pdo->prepare('SELECT * FROM events WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $row['id'] = $id;
        $row['sort_order'] = (int) $row['sort_order'];
        $response->getBody()->write(json_encode($row, JSON_THROW_ON_ERROR));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT * FROM events WHERE id = ?');
        $st->execute([$id]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }

        $fields = [];
        $bind = [];
        if (isset($body['title'])) {
            $t = trim((string) $body['title']);
            if ($t === '') {
                return $this->json($response, ['error' => 'Title cannot be empty'], 422);
            }
            $fields[] = 'title = ?';
            $bind[] = $t;
        }
        if (array_key_exists('slug', $body)) {
            $raw = trim((string) $body['slug']);
            $newSlug = $raw !== '' ? Slug::fromTitle($raw) : Slug::fromTitle((string) $existing['title']);
            $newSlug = Slug::unique($pdo, $newSlug, 'events', $id);
            $fields[] = 'slug = ?';
            $bind[] = $newSlug;
        }
        if (array_key_exists('excerpt', $body)) {
            $fields[] = 'excerpt = ?';
            $bind[] = trim((string) $body['excerpt']) ?: null;
        }
        if (array_key_exists('content_html', $body)) {
            $fields[] = 'content_html = ?';
            $bind[] = (string) $body['content_html'];
        }
        if (array_key_exists('sort_order', $body)) {
            $fields[] = 'sort_order = ?';
            $bind[] = (int) $body['sort_order'];
        }

        $hasSchedule = isset($body['status']) || array_key_exists('published_at', $body);
        if ($hasSchedule) {
            $nextStatus = isset($body['status'])
                ? ($body['status'] === 'published' ? 'published' : 'draft')
                : (string) $existing['status'];
            $nextPublished = array_key_exists('published_at', $body)
                ? (trim((string) $body['published_at']) !== '' ? trim((string) $body['published_at']) : null)
                : $existing['published_at'];
            if ($nextStatus === 'draft') {
                $nextPublished = null;
            } elseif ($nextStatus === 'published' && ($nextPublished === null || $nextPublished === '')) {
                $nextPublished = $existing['published_at']
                    ?: (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            }
            $fields[] = 'status = ?';
            $bind[] = $nextStatus;
            $fields[] = 'published_at = ?';
            $bind[] = $nextPublished;
        }

        if ($fields !== []) {
            $bind[] = $id;
            $pdo->prepare('UPDATE events SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($bind);
        }

        $st = $pdo->prepare('SELECT * FROM events WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $row['id'] = $id;
        $row['sort_order'] = (int) $row['sort_order'];
        $response->getBody()->write(json_encode($row, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('DELETE FROM events WHERE id = ?');
        $st->execute([$id]);
        if ($st->rowCount() === 0) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }
        return $response->withStatus(204);
    }

    private function json(ResponseInterface $response, array $payload, int $status = 400): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
