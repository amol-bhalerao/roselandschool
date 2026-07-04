<?php

declare(strict_types=1);

namespace BlogApi\Controllers;

use BlogApi\Database;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CarouselController
{
    public function __construct(private Database $db)
    {
    }

    public function listPublic(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $rows = $pdo->query(
            'SELECT id, sort_order, title, excerpt, image_url, body_html, link_post_id, link_url
             FROM carousel_slides ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->publicSlide($pdo, $r);
        }
        $response->getBody()->write(json_encode(['data' => $out], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function listAdmin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $rows = $pdo->query(
            'SELECT id, sort_order, title, excerpt, image_url, body_html, link_post_id, link_url
             FROM carousel_slides ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['sort_order'] = (int) $r['sort_order'];
            $r['link_post_id'] = $r['link_post_id'] !== null ? (int) $r['link_post_id'] : null;
        }
        unset($r);
        $response->getBody()->write(json_encode(['data' => $rows], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $title = trim((string) ($body['title'] ?? ''));
        $imageUrl = trim((string) ($body['image_url'] ?? ''));
        if ($title === '' || $imageUrl === '') {
            return $this->json($response, ['error' => 'Title and image_url are required'], 422);
        }
        $pdo = $this->db->pdo();
        $sortOrder = (int) ($body['sort_order'] ?? 0);
        $excerpt = isset($body['excerpt']) ? trim((string) $body['excerpt']) : '';
        $bodyHtml = isset($body['body_html']) ? (string) $body['body_html'] : '';
        $linkPostId = isset($body['link_post_id']) && $body['link_post_id'] !== '' && $body['link_post_id'] !== null
            ? (int) $body['link_post_id'] : null;
        $linkUrl = isset($body['link_url']) ? trim((string) $body['link_url']) : '';
        if ($linkUrl === '') {
            $linkUrl = null;
        }

        $st = $pdo->prepare(
            'INSERT INTO carousel_slides (sort_order, title, excerpt, image_url, body_html, link_post_id, link_url)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$sortOrder, $title, $excerpt !== '' ? $excerpt : null, $imageUrl, $bodyHtml, $linkPostId, $linkUrl]);
        $id = (int) $pdo->lastInsertId();
        $response->getBody()->write(json_encode(['id' => $id], JSON_THROW_ON_ERROR));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT id FROM carousel_slides WHERE id = ?');
        $st->execute([$id]);
        if (!$st->fetch()) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }

        $fields = [];
        $bind = [];
        foreach (['title', 'excerpt', 'image_url', 'body_html'] as $k) {
            if (array_key_exists($k, $body)) {
                $fields[] = "$k = ?";
                $bind[] = $k === 'body_html' ? (string) $body[$k] : trim((string) $body[$k]);
            }
        }
        if (array_key_exists('sort_order', $body)) {
            $fields[] = 'sort_order = ?';
            $bind[] = (int) $body['sort_order'];
        }
        if (array_key_exists('link_post_id', $body)) {
            $fields[] = 'link_post_id = ?';
            $v = $body['link_post_id'];
            $bind[] = $v === null || $v === '' ? null : (int) $v;
        }
        if (array_key_exists('link_url', $body)) {
            $u = trim((string) ($body['link_url'] ?? ''));
            $fields[] = 'link_url = ?';
            $bind[] = $u === '' ? null : $u;
        }
        if ($fields !== []) {
            $bind[] = $id;
            $pdo->prepare('UPDATE carousel_slides SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($bind);
        }
        return $response->withStatus(204);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('DELETE FROM carousel_slides WHERE id = ?');
        $st->execute([$id]);
        if ($st->rowCount() === 0) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }
        return $response->withStatus(204);
    }

    /** @param array<string, mixed> $row */
    private function publicSlide(PDO $pdo, array $row): array
    {
        $linkHref = $this->resolveLinkHref($pdo, $row);
        return [
            'id' => (int) $row['id'],
            'sort_order' => (int) $row['sort_order'],
            'title' => $row['title'],
            'excerpt' => $row['excerpt'],
            'image_url' => $row['image_url'],
            'body_html' => $row['body_html'] ?? '',
            'link_href' => $linkHref,
            'link_post_id' => $row['link_post_id'] !== null ? (int) $row['link_post_id'] : null,
        ];
    }

    /** @param array<string, mixed> $row */
    private function resolveLinkHref(PDO $pdo, array $row): string
    {
        if (!empty($row['link_url'])) {
            return (string) $row['link_url'];
        }
        if ($row['link_post_id'] !== null) {
            $st = $pdo->prepare('SELECT slug FROM posts WHERE id = ? AND status = ? LIMIT 1');
            $st->execute([(int) $row['link_post_id'], 'published']);
            $slug = $st->fetchColumn();
            if ($slug) {
                return '/post/' . rawurlencode((string) $slug);
            }
        }
        return '#';
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
