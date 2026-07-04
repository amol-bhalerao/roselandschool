<?php

declare(strict_types=1);

namespace BlogApi\Util;

use PDO;

final class Slug
{
    public static function fromTitle(string $title): string
    {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title;
        $s = strtolower((string) $s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        return $s !== '' ? $s : 'post';
    }

    public static function unique(PDO $pdo, string $base, string $table, ?int $excludeId = null): string
    {
        $slug = $base;
        $n = 2;
        while (self::exists($pdo, $table, $slug, $excludeId)) {
            $slug = $base . '-' . $n;
            $n++;
        }
        return $slug;
    }

    private static function exists(PDO $pdo, string $table, string $slug, ?int $excludeId): bool
    {
        if ($excludeId === null) {
            $st = $pdo->prepare("SELECT 1 FROM {$table} WHERE slug = ? LIMIT 1");
            $st->execute([$slug]);
        } else {
            $st = $pdo->prepare("SELECT 1 FROM {$table} WHERE slug = ? AND id <> ? LIMIT 1");
            $st->execute([$slug, $excludeId]);
        }
        return (bool) $st->fetchColumn();
    }
}
