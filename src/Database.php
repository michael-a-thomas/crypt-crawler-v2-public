<?php
declare(strict_types=1);

namespace CryptCrawler;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private PDO $pdo;

    public function __construct(private readonly string $path)
    {
        try {
            $this->pdo = new PDO('sqlite:' . $this->path);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function initialize(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS listings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source TEXT NOT NULL,
                canonical_url TEXT NOT NULL UNIQUE,
                url TEXT NOT NULL,
                title TEXT NOT NULL,
                description TEXT,
                price REAL,
                size TEXT,
                image_url TEXT,
                category TEXT,
                search_term TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_listings_source ON listings(source)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_listings_category ON listings(category)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_listings_size ON listings(size)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_listings_price ON listings(price)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_listings_search_term ON listings(search_term)");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS crawl_state (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                status TEXT NOT NULL DEFAULT 'idle',
                stop_requested INTEGER NOT NULL DEFAULT 0,
                current_source TEXT,
                current_search_term TEXT,
                current_method TEXT,
                last_error TEXT,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS crawl_progress (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source TEXT NOT NULL,
                search_term TEXT NOT NULL,
                method TEXT NOT NULL,
                target INTEGER NOT NULL,
                accepted INTEGER NOT NULL DEFAULT 0,
                duplicates_skipped INTEGER NOT NULL DEFAULT 0,
                rejected_filtered INTEGER NOT NULL DEFAULT 0,
                failed INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT 'pending',
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(source, search_term)
            )
        ");

        $exists = $this->pdo->query("SELECT COUNT(*) AS c FROM crawl_state WHERE id = 1")->fetch();
        if ((int)($exists['c'] ?? 0) === 0) {
            $this->pdo->exec("INSERT INTO crawl_state (id, status, stop_requested) VALUES (1, 'idle', 0)");
        }
    }
}
