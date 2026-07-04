<?php

declare(strict_types=1);

namespace BlogApi\Util;

use PDO;

/**
 * Hierarchical category helpers (parent_id on categories).
 */
final class CategorySubtree
{
    /** @return array<int, int[]> parent_id => [child ids] */
    public static function childrenMap(PDO $pdo): array
    {
        $st = $pdo->query('SELECT id, parent_id FROM categories');
        $map = [];
        while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
            $id = (int) $row['id'];
            if ($row['parent_id'] !== null) {
                $pid = (int) $row['parent_id'];
                $map[$pid][] = $id;
            }
        }
        return $map;
    }

    /**
     * Root and all descendants (BFS), unique.
     *
     * @param array<int, int[]> $childrenMap
     * @return int[]
     */
    public static function subtreeIdsFromRoot(int $rootId, array $childrenMap): array
    {
        $ids = [];
        $queue = [$rootId];
        while ($queue !== []) {
            $id = array_shift($queue);
            $ids[] = $id;
            foreach ($childrenMap[$id] ?? [] as $childId) {
                $queue[] = $childId;
            }
        }
        return $ids;
    }

    /**
     * Category ids for filtering posts (this category + all subcategories).
     *
     * @return int[]|null null if slug does not exist
     */
    public static function idsForSlug(PDO $pdo, string $slug): ?array
    {
        $st = $pdo->prepare('SELECT id FROM categories WHERE slug = ? LIMIT 1');
        $st->execute([$slug]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $map = self::childrenMap($pdo);
        return self::subtreeIdsFromRoot((int) $row['id'], $map);
    }

    /**
     * Count published posts assigned to any of the given categories.
     *
     * @param int[] $categoryIds
     */
    public static function countPublishedPostsInCategories(PDO $pdo, array $categoryIds): int
    {
        if ($categoryIds === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, \count($categoryIds), '?'));
        $st = $pdo->prepare(
            "SELECT COUNT(DISTINCT pc.post_id) FROM post_categories pc
             INNER JOIN posts p ON p.id = pc.post_id AND p.status = 'published'
             WHERE pc.category_id IN ($placeholders)"
        );
        $st->execute($categoryIds);
        return (int) $st->fetchColumn();
    }

    /**
     * Count posts (any status) assigned to any of the given categories.
     *
     * @param int[] $categoryIds
     */
    public static function countPostsInCategories(PDO $pdo, array $categoryIds): int
    {
        if ($categoryIds === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, \count($categoryIds), '?'));
        $st = $pdo->prepare(
            "SELECT COUNT(DISTINCT pc.post_id) FROM post_categories pc
             WHERE pc.category_id IN ($placeholders)"
        );
        $st->execute($categoryIds);
        return (int) $st->fetchColumn();
    }
}
