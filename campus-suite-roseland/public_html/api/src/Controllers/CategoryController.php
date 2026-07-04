<?php

declare(strict_types=1);

namespace BlogApi\Controllers;

use BlogApi\Database;
use BlogApi\Util\CategorySubtree;
use BlogApi\Util\Slug;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CategoryController
{
    public function __construct(private Database $db)
    {
    }

    public function listPublic(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $this->ensurePageTopicsSchema($pdo);
        $page = trim((string) ($request->getQueryParams()['page'] ?? ''));
        if ($page !== '' && $this->hasPageTopicMapping($pdo, $page)) {
            $st = $pdo->prepare(
                'SELECT c.id, c.parent_id, c.name, c.slug
                 FROM site_page_topics spt
                 JOIN categories c ON c.id = spt.category_id
                 WHERE spt.page_slug = ?
                 ORDER BY spt.sort_order ASC, c.name ASC'
            );
            $st->execute([$page]);
        } else {
            $st = $pdo->query(
                'SELECT c.id, c.parent_id, c.name, c.slug FROM categories c ORDER BY c.name'
            );
        }
        $rows = $st->fetchAll();
        $map = CategorySubtree::childrenMap($pdo);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['parent_id'] = $r['parent_id'] !== null ? (int) $r['parent_id'] : null;
            $sub = CategorySubtree::subtreeIdsFromRoot($r['id'], $map);
            $r['post_count'] = CategorySubtree::countPublishedPostsInCategories($pdo, $sub);
        }
        unset($r);
        $response->getBody()->write(json_encode(['data' => $rows], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function listAdmin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $this->ensurePageTopicsSchema($pdo);
        $st = $pdo->query(
            'SELECT c.id, c.parent_id, c.name, c.slug, c.created_at FROM categories c ORDER BY c.name'
        );
        $rows = $st->fetchAll();
        $map = CategorySubtree::childrenMap($pdo);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['parent_id'] = $r['parent_id'] !== null ? (int) $r['parent_id'] : null;
            $sub = CategorySubtree::subtreeIdsFromRoot($r['id'], $map);
            $r['post_count'] = CategorySubtree::countPostsInCategories($pdo, $sub);
        }
        unset($r);
        $response->getBody()->write(json_encode(['data' => $rows], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function pageTopicsAdmin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $this->ensurePageTopicsSchema($pdo);
        $page = trim((string) ($request->getQueryParams()['page'] ?? 'home'));
        if ($page === '') {
            $page = 'home';
        }
        $st = $pdo->prepare(
            'SELECT category_id, sort_order FROM site_page_topics WHERE page_slug = ? ORDER BY sort_order ASC, category_id ASC'
        );
        $st->execute([$page]);
        $rows = array_map(static fn (array $row): array => [
            'category_id' => (int) $row['category_id'],
            'sort_order' => (int) $row['sort_order'],
        ], $st->fetchAll());
        return $this->json($response, ['page' => $page, 'data' => $rows]);
    }

    public function updatePageTopicsAdmin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $this->ensurePageTopicsSchema($pdo);
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $page = trim((string) ($body['page'] ?? 'home'));
        if ($page === '') {
            $page = 'home';
        }
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM site_page_topics WHERE page_slug = ?')->execute([$page]);
            $ins = $pdo->prepare('INSERT INTO site_page_topics (page_slug, category_id, sort_order) VALUES (?, ?, ?)');
            foreach ($items as $i => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $categoryId = (int) ($item['category_id'] ?? 0);
                if ($categoryId <= 0 || !$this->categoryExists($pdo, $categoryId)) {
                    continue;
                }
                $sort = (int) ($item['sort_order'] ?? (($i + 1) * 10));
                $ins->execute([$page, $categoryId, $sort]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $this->pageTopicsAdmin($request->withQueryParams(['page' => $page]), $response);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->json($response, ['error' => 'Name is required'], 422);
        }
        $pdo = $this->db->pdo();
        $parentId = $this->normalizeParentId($body['parent_id'] ?? null);
        if ($parentId !== null && !$this->categoryExists($pdo, $parentId)) {
            return $this->json($response, ['error' => 'Parent category not found'], 422);
        }
        $base = Slug::fromTitle($name);
        $slug = Slug::unique($pdo, $base, 'categories');
        $st = $pdo->prepare('INSERT INTO categories (name, slug, parent_id) VALUES (?, ?, ?)');
        $st->execute([$name, $slug, $parentId]);
        $id = (int) $pdo->lastInsertId();
        $st = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        $row['id'] = $id;
        $response->getBody()->write(json_encode($row, JSON_THROW_ON_ERROR));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT id FROM categories WHERE id = ?');
        $st->execute([$id]);
        if (!$st->fetch()) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }

        $fields = [];
        $bind = [];
        if (array_key_exists('name', $body)) {
            $fields[] = 'name = ?';
            $bind[] = trim((string) $body['name']);
        }
        if (array_key_exists('slug', $body)) {
            $slugRaw = trim((string) $body['slug']);
            if ($slugRaw !== '') {
                $base = Slug::fromTitle($slugRaw);
                $bind[] = Slug::unique($pdo, $base, 'categories', $id);
                $fields[] = 'slug = ?';
            }
        }
        if (array_key_exists('parent_id', $body)) {
            $parentId = $this->normalizeParentId($body['parent_id']);
            if ($parentId !== null) {
                if (!$this->categoryExists($pdo, $parentId)) {
                    return $this->json($response, ['error' => 'Parent category not found'], 422);
                }
                if ($this->parentWouldCycle($pdo, $id, $parentId)) {
                    return $this->json($response, ['error' => 'Invalid parent (would create a cycle)'], 422);
                }
            }
            $fields[] = 'parent_id = ?';
            $bind[] = $parentId;
        }
        if ($fields !== []) {
            $bind[] = $id;
            $sql = 'UPDATE categories SET ' . implode(', ', $fields) . ' WHERE id = ?';
            $pdo->prepare($sql)->execute($bind);
        }

        $st = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        $row['id'] = $id;
        $response->getBody()->write(json_encode($row, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM post_categories WHERE category_id = ?')->execute([$id]);
        $st = $pdo->prepare('DELETE FROM categories WHERE id = ?');
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

    private function normalizeParentId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function categoryExists(PDO $pdo, int $id): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM categories WHERE id = ?');
        $st->execute([$id]);
        return (bool) $st->fetchColumn();
    }

    /** True if assigning parentId to category id would create a cycle. */
    private function parentWouldCycle(PDO $pdo, int $id, int $parentId): bool
    {
        if ($parentId === $id) {
            return true;
        }
        $map = CategorySubtree::childrenMap($pdo);
        $sub = CategorySubtree::subtreeIdsFromRoot($id, $map);
        return \in_array($parentId, $sub, true);
    }

    private function ensurePageTopicsSchema(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS site_page_topics (
              page_slug VARCHAR(200) NOT NULL,
              category_id INT UNSIGNED NOT NULL,
              sort_order INT NOT NULL DEFAULT 0,
              PRIMARY KEY (page_slug, category_id),
              KEY idx_site_page_topics_page (page_slug, sort_order),
              KEY idx_site_page_topics_category (category_id),
              CONSTRAINT fk_site_page_topics_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function hasPageTopicMapping(PDO $pdo, string $page): bool
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM site_page_topics WHERE page_slug = ?');
        $st->execute([$page]);
        return (int) $st->fetchColumn() > 0;
    }
}
