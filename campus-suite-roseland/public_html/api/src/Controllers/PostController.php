<?php

declare(strict_types=1);

namespace BlogApi\Controllers;

use BlogApi\Database;
use BlogApi\Util\CategorySubtree;
use BlogApi\Util\Slug;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PostController
{
    public function __construct(private Database $db)
    {
    }

    public function listPublic(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $per = min(50, max(1, (int) ($params['per_page'] ?? 12)));
        $offset = ($page - 1) * $per;
        $category = trim((string) ($params['category'] ?? ''));

        $pdo = $this->db->pdo();
        $where = "p.status = 'published'";
        $bind = [];
        if ($category !== '') {
            $catIds = CategorySubtree::idsForSlug($pdo, $category);
            if ($catIds === null) {
                $response->getBody()->write(json_encode([
                    'data' => [],
                    'meta' => [
                        'page' => $page,
                        'per_page' => $per,
                        'total' => 0,
                        'total_pages' => 0,
                    ],
                ], JSON_THROW_ON_ERROR));
                return $response->withHeader('Content-Type', 'application/json');
            }
            $placeholders = implode(',', array_fill(0, count($catIds), '?'));
            $where .= " AND pc.category_id IN ($placeholders)";
            foreach ($catIds as $cid) {
                $bind[] = $cid;
            }
        }

        $countSql = "SELECT COUNT(DISTINCT p.id) FROM posts p
            LEFT JOIN post_categories pc ON pc.post_id = p.id
            LEFT JOIN categories c ON c.id = pc.category_id
            WHERE $where";
        $stc = $pdo->prepare($countSql);
        $stc->execute($bind);
        $total = (int) $stc->fetchColumn();

        $sql = "SELECT DISTINCT p.id, p.title, p.slug, p.excerpt, p.cover_image_url, p.published_at, p.created_at
            FROM posts p
            LEFT JOIN post_categories pc ON pc.post_id = p.id
            LEFT JOIN categories c ON c.id = pc.category_id
            WHERE $where
            ORDER BY p.published_at IS NULL, p.published_at DESC, p.id DESC
            LIMIT $per OFFSET $offset";
        $st = $pdo->prepare($sql);
        $st->execute($bind);
        $rows = $st->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['categories'] = $this->categoriesForPost($pdo, (int) $row['id']);
        }
        unset($row);

        $response->getBody()->write(json_encode([
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $per,
                'total' => $total,
                'total_pages' => (int) ceil($total / $per),
            ],
        ], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getBySlugPublic(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = $args['slug'] ?? '';
        $pdo = $this->db->pdo();
        $st = $pdo->prepare(
            'SELECT p.*, u.email AS author_email FROM posts p
            JOIN users u ON u.id = p.user_id
            WHERE p.slug = ? AND p.status = ? LIMIT 1'
        );
        $st->execute([$slug, 'published']);
        $post = $st->fetch();
        if (!$post) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }
        $post['id'] = (int) $post['id'];
        $post['user_id'] = (int) $post['user_id'];
        $post['categories'] = $this->categoriesForPost($pdo, $post['id']);
        $response->getBody()->write(json_encode($post, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function listAdmin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $per = min(100, max(1, (int) ($params['per_page'] ?? 20)));
        $offset = ($page - 1) * $per;
        $status = trim((string) ($params['status'] ?? ''));
        $q = trim((string) ($params['q'] ?? ''));

        $pdo = $this->db->pdo();
        $where = '1=1';
        $bind = [];
        if ($status !== '' && in_array($status, ['draft', 'published'], true)) {
            $where .= ' AND p.status = ?';
            $bind[] = $status;
        }
        if ($q !== '') {
            $where .= ' AND (p.title LIKE ? OR p.slug LIKE ?)';
            $bind[] = '%' . $q . '%';
            $bind[] = '%' . $q . '%';
        }

        $countSql = "SELECT COUNT(*) FROM posts p WHERE $where";
        $stc = $pdo->prepare($countSql);
        $stc->execute($bind);
        $total = (int) $stc->fetchColumn();

        $sql = "SELECT p.id, p.title, p.slug, p.status, p.published_at, p.created_at, p.updated_at, u.email AS author_email
            FROM posts p JOIN users u ON u.id = p.user_id
            WHERE $where
            ORDER BY p.updated_at DESC
            LIMIT $per OFFSET $offset";
        $st = $pdo->prepare($sql);
        $st->execute($bind);
        $rows = $st->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $pid = $row['id'];
            $row['categories'] = $this->categoriesForPost($pdo, $pid);
            $row['nav_menu_labels'] = $this->navLabelsForPost($pdo, $pid);
        }
        unset($row);

        $response->getBody()->write(json_encode([
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $per,
                'total' => $total,
                'total_pages' => (int) ceil($total / $per),
            ],
        ], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getById(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $pdo = $this->db->pdo();
        $st = $pdo->prepare(
            'SELECT p.*, u.email AS author_email FROM posts p JOIN users u ON u.id = p.user_id WHERE p.id = ?'
        );
        $st->execute([$id]);
        $post = $st->fetch();
        if (!$post) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }
        $post['id'] = (int) $post['id'];
        $post['user_id'] = (int) $post['user_id'];
        $post['category_ids'] = $this->categoryIdsForPost($pdo, $id);
        $response->getBody()->write(json_encode($post, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = (int) $request->getAttribute('user_id');
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            return $this->json($response, ['error' => 'Title is required'], 422);
        }

        $pdo = $this->db->pdo();
        $slugInput = trim((string) ($body['slug'] ?? ''));
        $base = $slugInput !== '' ? Slug::fromTitle($slugInput) : Slug::fromTitle($title);
        $slug = Slug::unique($pdo, $base, 'posts');
        $excerpt = trim((string) ($body['excerpt'] ?? ''));
        $content = (string) ($body['content_html'] ?? '');
        $cover = trim((string) ($body['cover_image_url'] ?? ''));
        $status = ($body['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        $publishedAt = null;
        if ($status === 'published') {
            $publishedAt = $body['published_at'] ?? date('Y-m-d H:i:s');
        }

        $pdf = trim((string) ($body['pdf_url'] ?? ''));
        $st = $pdo->prepare(
            'INSERT INTO posts (user_id, title, slug, excerpt, content_html, cover_image_url, pdf_url, status, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$userId, $title, $slug, $excerpt, $content, $cover ?: null, $pdf !== '' ? $pdf : null, $status, $publishedAt]);
        $id = (int) $pdo->lastInsertId();

        $this->syncCategories($pdo, $id, $body['category_ids'] ?? []);

        $st = $pdo->prepare('SELECT * FROM posts WHERE id = ?');
        $st->execute([$id]);
        $post = $st->fetch();
        $post['id'] = $id;
        $post['category_ids'] = $this->categoryIdsForPost($pdo, $id);

        $response->getBody()->write(json_encode($post, JSON_THROW_ON_ERROR));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $pdo = $this->db->pdo();

        $st = $pdo->prepare('SELECT id FROM posts WHERE id = ?');
        $st->execute([$id]);
        if (!$st->fetch()) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }

        $fields = [];
        $bind = [];
        if (array_key_exists('title', $body)) {
            $fields[] = 'title = ?';
            $bind[] = trim((string) $body['title']);
        }
        if (array_key_exists('slug', $body)) {
            $slugRaw = trim((string) $body['slug']);
            if ($slugRaw !== '') {
                $base = Slug::fromTitle($slugRaw);
                $bind[] = Slug::unique($pdo, $base, 'posts', $id);
                $fields[] = 'slug = ?';
            }
        }
        if (array_key_exists('excerpt', $body)) {
            $fields[] = 'excerpt = ?';
            $bind[] = trim((string) $body['excerpt']);
        }
        if (array_key_exists('content_html', $body)) {
            $fields[] = 'content_html = ?';
            $bind[] = (string) $body['content_html'];
        }
        if (array_key_exists('cover_image_url', $body)) {
            $fields[] = 'cover_image_url = ?';
            $v = trim((string) $body['cover_image_url']);
            $bind[] = $v !== '' ? $v : null;
        }
        if (array_key_exists('pdf_url', $body)) {
            $fields[] = 'pdf_url = ?';
            $v = trim((string) $body['pdf_url']);
            $bind[] = $v !== '' ? $v : null;
        }
        if (array_key_exists('status', $body)) {
            $stPub = ($body['status'] ?? '') === 'published' ? 'published' : 'draft';
            $fields[] = 'status = ?';
            $bind[] = $stPub;
            if ($stPub === 'published' && !array_key_exists('published_at', $body)) {
                $fields[] = 'published_at = COALESCE(published_at, ?)';
                $bind[] = date('Y-m-d H:i:s');
            }
        }
        if (array_key_exists('published_at', $body)) {
            $fields[] = 'published_at = ?';
            $bind[] = $body['published_at'];
        }

        if ($fields !== []) {
            $fields[] = 'updated_at = CURRENT_TIMESTAMP';
            $bind[] = $id;
            $sql = 'UPDATE posts SET ' . implode(', ', $fields) . ' WHERE id = ?';
            $st = $pdo->prepare($sql);
            $st->execute($bind);
        }

        if (array_key_exists('category_ids', $body)) {
            $this->syncCategories($pdo, $id, $body['category_ids']);
        }

        $st = $pdo->prepare('SELECT p.*, u.email AS author_email FROM posts p JOIN users u ON u.id = p.user_id WHERE p.id = ?');
        $st->execute([$id]);
        $post = $st->fetch();
        $post['id'] = $id;
        $post['category_ids'] = $this->categoryIdsForPost($pdo, $id);

        $response->getBody()->write(json_encode($post, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('DELETE FROM posts WHERE id = ?');
        $st->execute([$id]);
        if ($st->rowCount() === 0) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }
        return $response->withStatus(204);
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
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
        }
        return $rows;
    }

    /** @return list<int> */
    private function categoryIdsForPost(PDO $pdo, int $postId): array
    {
        $st = $pdo->prepare('SELECT category_id FROM post_categories WHERE post_id = ?');
        $st->execute([$postId]);
        return array_map(static fn ($id) => (int) $id, $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    private function navLabelsForPost(PDO $pdo, int $postId): array
    {
        $st = $pdo->prepare('SELECT label FROM nav_items WHERE post_id = ? ORDER BY sort_order ASC, id ASC');
        $st->execute([$postId]);
        $cols = $st->fetchAll(PDO::FETCH_COLUMN);
        return array_map(static fn ($s) => (string) $s, $cols);
    }

    /** @param mixed $ids */
    private function syncCategories(PDO $pdo, int $postId, $ids): void
    {
        $pdo->prepare('DELETE FROM post_categories WHERE post_id = ?')->execute([$postId]);
        if (!is_array($ids)) {
            return;
        }
        $ins = $pdo->prepare('INSERT INTO post_categories (post_id, category_id) VALUES (?, ?)');
        foreach ($ids as $cid) {
            $cid = (int) $cid;
            if ($cid <= 0) {
                continue;
            }
            $check = $pdo->prepare('SELECT 1 FROM categories WHERE id = ?');
            $check->execute([$cid]);
            if ($check->fetch()) {
                $ins->execute([$postId, $cid]);
            }
        }
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
