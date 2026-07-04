<?php

declare(strict_types=1);

namespace BlogApi\Controllers;

use BlogApi\Database;
use BlogApi\Util\Slug;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SitePageController
{
    public function __construct(private Database $db)
    {
    }

    public function getBySlugPublic(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = trim((string) ($args['slug'] ?? ''));
        if ($slug === '') {
            return $this->json($response, ['error' => 'Not found'], 404);
        }
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT id, slug, title, content_html, updated_at FROM site_pages WHERE slug = ? LIMIT 1');
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
        $rows = $pdo->query('SELECT id, slug, title, updated_at FROM site_pages ORDER BY title')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
        }
        unset($r);
        $response->getBody()->write(json_encode(['data' => $rows], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getAdmin(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT * FROM site_pages WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }
        $row['id'] = (int) $row['id'];
        $response->getBody()->write(json_encode($row, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getAdminBySlug(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = trim((string) ($args['slug'] ?? ''));
        if ($slug === '') {
            return $this->json($response, ['error' => 'Not found'], 404);
        }
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT * FROM site_pages WHERE slug = ? LIMIT 1');
        $st->execute([$slug]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }
        $row['id'] = (int) $row['id'];
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
        $slug = Slug::unique($pdo, $base, 'site_pages');
        $st = $pdo->prepare('INSERT INTO site_pages (slug, title, content_html) VALUES (?, ?, ?)');
        $st->execute([$slug, $title, $contentHtml]);
        $id = (int) $pdo->lastInsertId();
        $st = $pdo->prepare('SELECT * FROM site_pages WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $row['id'] = $id;
        $response->getBody()->write(json_encode($row, JSON_THROW_ON_ERROR));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT id FROM site_pages WHERE id = ?');
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
        if (array_key_exists('content_html', $body)) {
            $fields[] = 'content_html = ?';
            $bind[] = (string) $body['content_html'];
        }
        if (array_key_exists('slug', $body)) {
            $slugRaw = trim((string) $body['slug']);
            if ($slugRaw !== '') {
                $base = Slug::fromTitle($slugRaw);
                $fields[] = 'slug = ?';
                $bind[] = Slug::unique($pdo, $base, 'site_pages', $id);
            }
        }
        if ($fields !== []) {
            $bind[] = $id;
            $pdo->prepare('UPDATE site_pages SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($bind);
        }

        $st = $pdo->prepare('SELECT * FROM site_pages WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $row['id'] = $id;
        $response->getBody()->write(json_encode($row, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('DELETE FROM site_pages WHERE id = ?');
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
