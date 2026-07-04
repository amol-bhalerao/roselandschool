<?php

declare(strict_types=1);

namespace BlogApi\Controllers;

use BlogApi\Database;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class StatsController
{
    public function __construct(private Database $db)
    {
    }

    public function overview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pdo = $this->db->pdo();
        $posts = (int) $pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn();
        $published = (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'published'")->fetchColumn();
        $drafts = (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'draft'")->fetchColumn();
        $categories = (int) $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
        $events = $this->countTable($pdo, 'events');
        $sitePages = (int) $pdo->query("SELECT COUNT(*) FROM site_pages")->fetchColumn();
        $gallery = $this->countTable($pdo, 'gallery_items');

        $response->getBody()->write(json_encode([
            'posts_total' => $posts,
            'posts_published' => $published,
            'posts_draft' => $drafts,
            'categories' => $categories,
            'events_total' => $events,
            'site_pages' => $sitePages,
            'gallery_items' => $gallery,
        ], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function countTable(\PDO $pdo, string $table): int
    {
        try {
            return (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }
}
