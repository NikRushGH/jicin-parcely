<?php

declare(strict_types=1);

namespace App;

use PDO;

class Database
{
    private ?PDO $pdo = null;

    public function __construct(private string $dbPath)
    {
    }

    public function getConnection(): PDO
    {
        if ($this->pdo === null) {
            // Připojení k SQLite databázi
            $this->pdo = new PDO('sqlite:' . $this->dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // Optimalizace výkonu SQLite (WAL mód)
            $this->pdo->exec('PRAGMA journal_mode = WAL;');
            $this->pdo->exec('PRAGMA synchronous = NORMAL;');
        }

        return $this->pdo;
    }

    public function initSchema(): void
    {
        $pdo = $this->getConnection();

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS parcels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cuzk_id TEXT UNIQUE,
                parcel_number TEXT NOT NULL,
                cadastral_area_code TEXT NOT NULL,
                cadastral_area_name TEXT NOT NULL,
                area_sqm INTEGER,
                land_type TEXT,
                min_lat REAL NOT NULL,
                max_lat REAL NOT NULL,
                min_lng REAL NOT NULL,
                max_lng REAL NOT NULL,
                center_lat REAL NOT NULL,
                center_lng REAL NOT NULL,
                geometry_json TEXT NOT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_parcels_bbox 
            ON parcels (min_lat, max_lat, min_lng, max_lng);

            CREATE INDEX IF NOT EXISTS idx_parcels_search 
            ON parcels (cadastral_area_code, parcel_number);
        ");
    }
}