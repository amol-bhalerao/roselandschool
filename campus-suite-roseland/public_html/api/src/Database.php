<?php

declare(strict_types=1);

namespace BlogApi;

use PDO;

final class Database
{
    public function __construct(private PDO $pdo)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
