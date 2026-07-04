<?php

declare(strict_types=1);

namespace BlogApi\Controllers;

use BlogApi\Database;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class NavigationController
{
    public function __construct(private Database $db)
    {
    }

    public function treePublic(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $rows = $this->fetchNavRows($pdo);
        $tree = $this->buildNavTree($rows, null);
        $response->getBody()->write(json_encode(['data' => $tree], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function listAdmin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $rows = $this->fetchNavRows($pdo);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
                'sort_order' => (int) $r['sort_order'],
                'label' => $r['label'],
                'page_id' => $r['page_id'] !== null ? (int) $r['page_id'] : null,
                'post_id' => isset($r['post_id']) && $r['post_id'] !== null ? (int) $r['post_id'] : null,
                'url' => $r['url'],
                'href' => $this->href($r),
            ];
        }
        $response->getBody()->write(json_encode(['data' => $out], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $label = trim((string) ($body['label'] ?? ''));
        if ($label === '') {
            return $this->json($response, ['error' => 'Label is required'], 422);
        }
        $parentId = isset($body['parent_id']) && $body['parent_id'] !== '' && $body['parent_id'] !== null
            ? (int) $body['parent_id'] : null;
        $sortOrder = (int) ($body['sort_order'] ?? 0);
        $pageId = isset($body['page_id']) && $body['page_id'] !== '' && $body['page_id'] !== null
            ? (int) $body['page_id'] : null;
        $postId = isset($body['post_id']) && $body['post_id'] !== '' && $body['post_id'] !== null
            ? (int) $body['post_id'] : null;
        $url = isset($body['url']) ? trim((string) $body['url']) : '';
        if ($url === '') {
            $url = null;
        }

        $err = $this->validateTarget($pageId, $url, $postId);
        if ($err !== null) {
            return $this->json($response, ['error' => $err], 422);
        }

        $pdo = $this->db->pdo();
        if ($parentId !== null && !$this->navExists($pdo, $parentId)) {
            return $this->json($response, ['error' => 'Parent menu item not found'], 422);
        }
        if ($pageId !== null && !$this->pageExists($pdo, $pageId)) {
            return $this->json($response, ['error' => 'Page not found'], 422);
        }
        if ($postId !== null && !$this->postExists($pdo, $postId)) {
            return $this->json($response, ['error' => 'Post not found'], 422);
        }

        $st = $pdo->prepare(
            'INSERT INTO nav_items (parent_id, sort_order, label, page_id, post_id, url) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$parentId, $sortOrder, $label, $pageId, $postId, $url]);
        $id = (int) $pdo->lastInsertId();
        $response->getBody()->write(json_encode(['id' => $id], JSON_THROW_ON_ERROR));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT id, parent_id, sort_order, label, page_id, post_id, url FROM nav_items WHERE id = ?');
        $st->execute([$id]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }

        $label = array_key_exists('label', $body) ? trim((string) $body['label']) : (string) $cur['label'];
        $sortOrder = array_key_exists('sort_order', $body) ? (int) $body['sort_order'] : (int) $cur['sort_order'];
        $parentId = array_key_exists('parent_id', $body)
            ? ($body['parent_id'] === null || $body['parent_id'] === '' ? null : (int) $body['parent_id'])
            : ($cur['parent_id'] !== null ? (int) $cur['parent_id'] : null);

        $pageId = isset($cur['page_id']) && $cur['page_id'] !== null ? (int) $cur['page_id'] : null;
        $postId = isset($cur['post_id']) && $cur['post_id'] !== null ? (int) $cur['post_id'] : null;
        $url = $cur['url'] ? (string) $cur['url'] : null;

        if (array_key_exists('page_id', $body)) {
            $pageId = $body['page_id'] === null || $body['page_id'] === '' ? null : (int) $body['page_id'];
            $url = null;
            $postId = null;
        }
        if (array_key_exists('url', $body)) {
            $u = trim((string) $body['url']);
            $url = $u === '' ? null : $u;
            $pageId = null;
            $postId = null;
        }
        if (array_key_exists('post_id', $body)) {
            $postId = $body['post_id'] === null || $body['post_id'] === '' ? null : (int) $body['post_id'];
            $pageId = null;
            $url = null;
        }

        if ($parentId === $id) {
            return $this->json($response, ['error' => 'Invalid parent'], 422);
        }
        if ($parentId !== null) {
            if (!$this->navExists($pdo, $parentId) || $this->navInSubtree($pdo, $id, $parentId)) {
                return $this->json($response, ['error' => 'Invalid parent'], 422);
            }
        }

        $err = $this->validateTarget($pageId, $url, $postId);
        if ($err !== null) {
            return $this->json($response, ['error' => $err], 422);
        }
        if ($pageId !== null && !$this->pageExists($pdo, $pageId)) {
            return $this->json($response, ['error' => 'Page not found'], 422);
        }
        if ($postId !== null && !$this->postExists($pdo, $postId)) {
            return $this->json($response, ['error' => 'Post not found'], 422);
        }

        $pdo->prepare(
            'UPDATE nav_items SET parent_id = ?, sort_order = ?, label = ?, page_id = ?, post_id = ?, url = ? WHERE id = ?'
        )->execute([$parentId, $sortOrder, $label, $pageId, $postId, $url, $id]);

        return $response->withStatus(204);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('DELETE FROM nav_items WHERE id = ?');
        $st->execute([$id]);
        if ($st->rowCount() === 0) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }
        return $response->withStatus(204);
    }

    /** @return list<array<string, mixed>> */
    private function fetchNavRows(PDO $pdo): array
    {
        $sql = 'SELECT n.id, n.parent_id, n.sort_order, n.label, n.page_id, n.post_id, n.url,
                       p.slug AS page_slug, po.slug AS post_slug
                FROM nav_items n
                LEFT JOIN site_pages p ON p.id = n.page_id
                LEFT JOIN posts po ON po.id = n.post_id
                ORDER BY n.parent_id IS NOT NULL, n.parent_id, n.sort_order, n.id';
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<array<string, mixed>> $rows */
    private function buildNavTree(array $rows, ?int $parentId): array
    {
        $siblings = [];
        foreach ($rows as $r) {
            $pid = $r['parent_id'] !== null ? (int) $r['parent_id'] : null;
            if ($pid !== $parentId) {
                continue;
            }
            $siblings[] = $r;
        }
        usort($siblings, fn ($a, $b) => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));

        $out = [];
        foreach ($siblings as $r) {
            $nid = (int) $r['id'];
            $out[] = [
                'id' => $nid,
                'label' => $r['label'],
                'href' => $this->href($r),
                'children' => $this->buildNavTree($rows, $nid),
            ];
        }
        return $out;
    }

    /** @param array<string, mixed> $row */
    private function href(array $row): string
    {
        if (!empty($row['url'])) {
            return (string) $row['url'];
        }
        if (!empty($row['page_slug'])) {
            return '/p/' . rawurlencode((string) $row['page_slug']);
        }
        if (!empty($row['post_slug'])) {
            return '/post/' . rawurlencode((string) $row['post_slug']);
        }
        return '#';
    }

    private function validateTarget(?int $pageId, ?string $url, ?int $postId): ?string
    {
        $n = ($pageId !== null ? 1 : 0) + ($url !== null && $url !== '' ? 1 : 0) + ($postId !== null ? 1 : 0);
        if ($n !== 1) {
            return 'Set exactly one target: site page (page_id), published post (post_id), or url';
        }
        return null;
    }

    private function navExists(PDO $pdo, int $id): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM nav_items WHERE id = ?');
        $st->execute([$id]);
        return (bool) $st->fetchColumn();
    }

    private function pageExists(PDO $pdo, int $id): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM site_pages WHERE id = ?');
        $st->execute([$id]);
        return (bool) $st->fetchColumn();
    }

    private function postExists(PDO $pdo, int $id): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM posts WHERE id = ?');
        $st->execute([$id]);
        return (bool) $st->fetchColumn();
    }

    /** True if candidateId is rootId or a descendant of rootId */
    private function navInSubtree(PDO $pdo, int $rootId, int $candidateId): bool
    {
        if ($candidateId === $rootId) {
            return true;
        }
        $walk = $candidateId;
        for ($i = 0; $i < 100; $i++) {
            $st = $pdo->prepare('SELECT parent_id FROM nav_items WHERE id = ?');
            $st->execute([$walk]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['parent_id'] === null) {
                return false;
            }
            $p = (int) $row['parent_id'];
            if ($p === $rootId) {
                return true;
            }
            $walk = $p;
        }
        return false;
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
