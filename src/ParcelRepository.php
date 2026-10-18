<?php

declare(strict_types=1);

namespace App;

use PDO;

class ParcelRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Vyhledá parcely, které zasahují do aktuálního výřezu mapy (Bounding Box).
     */
    public function findInBoundingBox(
        float $minLat,
        float $maxLat,
        float $minLng,
        float $maxLng,
        ?string $cadastralCode = null,
        ?string $landType = null,
        int $limit = 25000
    ): array {
        $sql = "
            SELECT 
                id, cuzk_id, parcel_number, cadastral_area_code, 
                cadastral_area_name, area_sqm, land_type, geometry_json
            FROM parcels
            WHERE max_lat >= :minLat
              AND min_lat <= :maxLat
              AND max_lng >= :minLng
              AND min_lng <= :maxLng
        ";

        $params = [
            ':minLat' => $minLat,
            ':maxLat' => $maxLat,
            ':minLng' => $minLng,
            ':maxLng' => $maxLng,
        ];

        if (!empty($cadastralCode)) {
            $sql .= " AND cadastral_area_code = :cadastralCode";
            $params[':cadastralCode'] = $cadastralCode;
        }

        if (!empty($landType)) {
            $sql .= " AND land_type = :landType";
            $params[':landType'] = $landType;
        }

        $sql .= " LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Vrátí kompletní detail konkrétní parcely podle interního ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM parcels WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Vyhledávání parcely podle parcelního čísla (např. '125/2' nebo '125').
     */
    public function searchByNumber(string $query, ?string $cadastralCode = null): array
    {
        $sql = "
            SELECT id, parcel_number, cadastral_area_code, cadastral_area_name, 
                   area_sqm, land_type, center_lat, center_lng
            FROM parcels
            WHERE parcel_number LIKE :query
        ";
        $params = [':query' => $query . '%'];

        if (!empty($cadastralCode)) {
            $sql .= " AND cadastral_area_code = :code";
            $params[':code'] = $cadastralCode;
        }

        $sql .= " ORDER BY LENGTH(parcel_number) ASC, parcel_number ASC LIMIT 20";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Vrátí přehled načtených katastrálních území a statistiky pro filtry.
     */
    public function getMetadata(): array
    {
        $areas = $this->pdo->query("
            SELECT cadastral_area_code AS code, cadastral_area_name AS name, 
                   COUNT(*) AS parcel_count,
                   AVG(center_lat) AS lat, AVG(center_lng) AS lng
            FROM parcels
            GROUP BY cadastral_area_code, cadastral_area_name
            ORDER BY name ASC
        ")->fetchAll();

        $landTypes = $this->pdo->query("
            SELECT DISTINCT land_type FROM parcels WHERE land_type IS NOT NULL ORDER BY land_type
        ")->fetchAll(PDO::FETCH_COLUMN);

        return [
            'cadastral_areas' => $areas,
            'land_types' => $landTypes,
        ];
    }
}