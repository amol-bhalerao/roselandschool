<?php

declare(strict_types=1);

namespace BlogApi\Controllers;

use BlogApi\Database;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HighlightsController
{
    public function __construct(private Database $db)
    {
    }

    public function listPublic(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $news = $this->slotPost($pdo, 'news');
        $events = $this->slotPost($pdo, 'events');
        $response->getBody()->write(json_encode([
            'news' => $news,
            'events' => $events,
        ], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function adminGet(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $newsId = $this->slotPostId($pdo, 'news');
        $eventsId = $this->slotPostId($pdo, 'events');
        $response->getBody()->write(json_encode([
            'news_post_id' => $newsId,
            'events_post_id' => $eventsId,
        ], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function updateAdmin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $pdo = $this->db->pdo();
        $newsId = array_key_exists('news_post_id', $body)
            ? ($body['news_post_id'] === null || $body['news_post_id'] === '' ? null : (int) $body['news_post_id'])
            : $this->slotPostId($pdo, 'news');
        $eventsId = array_key_exists('events_post_id', $body)
            ? ($body['events_post_id'] === null || $body['events_post_id'] === '' ? null : (int) $body['events_post_id'])
            : $this->slotPostId($pdo, 'events');

        foreach (['news' => $newsId, 'events' => $eventsId] as $slot => $pid) {
            if ($pid !== null && !$this->postExists($pdo, $pid)) {
                return $this->json($response, ['error' => "Invalid post for slot {$slot}"], 422);
            }
        }

        $st = $pdo->prepare('INSERT INTO highlight_slots (slot_key, post_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE post_id = VALUES(post_id)');
        $st->execute(['news', $newsId]);
        $st->execute(['events', $eventsId]);

        return $response->withStatus(204);
    }

    private function slotPostId(PDO $pdo, string $slot): ?int
    {
        $st = $pdo->prepare('SELECT post_id FROM highlight_slots WHERE slot_key = ?');
        $st->execute([$slot]);
        $v = $st->fetchColumn();
        return $v !== false && $v !== null ? (int) $v : null;
    }

    /** @return array<string, mixed>|null */
    private function slotPost(PDO $pdo, string $slot): ?array
    {
        $pid = $this->slotPostId($pdo, $slot);
        if ($pid === null) {
            return null;
        }
        $st = $pdo->prepare(
            "SELECT id, title, slug, excerpt, cover_image_url, published_at, created_at
             FROM posts WHERE id = ? AND status = 'published' LIMIT 1"
        );
        $st->execute([$pid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['categories'] = $this->categoriesForPost($pdo, $row['id']);
        return $row;
    }

    private function postExists(PDO $pdo, int $id): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM posts WHERE id = ?');
        $st->execute([$id]);
        return (bool) $st->fetchColumn();
    }

    /** @return list<array{id:int,name:string,slug:string}> */
    private function categoriesForPost(PDO $pdo, int $postId): array
    {
        $st = $pdo->prepare(
            'SELECT c.id, c.name, c.slug FROM categories c
             INNER JOIN post_categories pc ON pc.category_id = c.id
             WHERE pc.post_id = ? ORDER BY c.name'
        );
        $st->execute([$postId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
        }
        unset($r);
        return $rows;
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
