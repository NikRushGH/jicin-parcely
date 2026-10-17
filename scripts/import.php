<?php

declare(strict_types=1);

ini_set('memory_limit', '512M');
set_time_limit(300);

require_once __DIR__ . '/../src/Database.php';

use App\Database;

$dbPath = __DIR__ . '/../data/database.sqlite';
if (!is_dir(dirname($dbPath))) {
    mkdir(dirname($dbPath), 0777, true);
}

$db = new Database($dbPath);
$db->initSchema();
$pdo = $db->getConnection();

// Katastrální území v okrese Jičín
$cadastralAreas = [
    '659541' => ['name' => 'Jičín'],
    '776530' => ['name' => 'Valdice'],
    '641243' => ['name' => 'Holín'],
    '723754' => ['name' => 'Staré Místo'],
    '796123' => ['name' => 'Železnice'],
];


echo "=== Inicializace importu katastrálních dat (Okres Jičín) ===\n";

$pdo->exec("DELETE FROM parcels;");

$insertStmt = $pdo->prepare("
    INSERT OR REPLACE INTO parcels (
        cuzk_id, parcel_number, cadastral_area_code, cadastral_area_name,
        area_sqm, land_type, min_lat, max_lat, min_lng, max_lng,
        center_lat, center_lng, geometry_json
    ) VALUES (
        :cuzk_id, :parcel_number, :cadastral_area_code, :cadastral_area_name,
        :area_sqm, :land_type, :min_lat, :max_lat, :min_lng, :max_lng,
        :center_lat, :center_lng, :geometry_json
    )
");



function parseEtrs89Coords(float $c1, float $c2): array
{
    $lat = max($c1, $c2);
    $lng = min($c1, $c2);
    return [round($lat, 6), round($lng, 6)];
}


function downloadFile(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) JicinParcely/1.0',
        ]);
        $data = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($data !== false && $code === 200 && strlen($data) > 500) {
            return $data;
        }
    }

    $ctx = stream_context_create([
        'http' => ['timeout' => 20, 'user_agent' => 'JicinParcely/1.0'],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);
    $data = @file_get_contents($url, false, $ctx);
    return ($data !== false && strlen($data) > 500) ? $data : null;
}

function extractXmlFromZip(string $zipPath): ?string
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) === true) {
            $xml = $zip->getFromIndex(0);
            $zip->close();
            if ($xml) return $xml;
        }
    }
    
    return null;
}

function importFromCuzkInspire(string $kuCode, string $kuName, PDO $pdo, PDOStatement $stmt): int
{
    $urls = [
        "https://services.cuzk.cz/gml/inspire/cp/epsg-4258/{$kuCode}.zip",
    ];

    $content = null;
    foreach ($urls as $url) {
        $content = downloadFile($url);
        if ($content !== null) break;
    }

    if ($content === null) {
        return 0;
    }

    $zipPath = __DIR__ . "/../data/{$kuCode}.zip";
    file_put_contents($zipPath, $content);
    $xmlContent = extractXmlFromZip($zipPath);
    @unlink($zipPath);

    if (!$xmlContent) {
        return 0;
    }

    preg_match_all('/<(?:\w+:)?CadastralParcel\b[^>]*>(.*?)<\/(?:\w+:)?CadastralParcel>/s', $xmlContent, $matches);
    if (empty($matches[1])) {
        return 0;
    }

    $count = 0;
    $pdo->beginTransaction();

    foreach ($matches[1] as $block) {
        if (!preg_match('/<(?:\w+:)?label>(.*?)<\/(?:\w+:)?label>/', $block, $mLabel)) continue;
        if (!preg_match('/<(?:\w+:)?posList[^>]*>(.*?)<\/(?:\w+:)?posList>/s', $block, $mPos)) continue;

        $parcelNumber = trim($mLabel[1]);
        $areaSqm = 0;
        if (preg_match('/<(?:\w+:)?areaValue[^>]*>(.*?)<\/(?:\w+:)?areaValue>/', $block, $mArea)) {
            $areaSqm = (int)round((float)$mArea[1]);
        }
        $cuzkId = $kuCode . '_' . $parcelNumber . '_' . $count;
        if (preg_match('/<(?:\w+:)?localId>(.*?)<\/(?:\w+:)?localId>/', $block, $mId)) {
            $cuzkId = trim($mId[1]);
        }

        $rawCoords = preg_split('/\s+/', trim($mPos[1]));
        $ring = [];
        $minLat = 90.0;  $maxLat = -90.0;
        $minLng = 180.0; $maxLng = -180.0;

        for ($i = 0; $i < count($rawCoords) - 1; $i += 2) {
            [$lat, $lng] = parseEtrs89Coords((float)$rawCoords[$i], (float)$rawCoords[$i + 1]);
            $ring[] = [$lng, $lat];

            if ($lat < $minLat) $minLat = $lat;
            if ($lat > $maxLat) $maxLat = $lat;
            if ($lng < $minLng) $minLng = $lng;
            if ($lng > $maxLng) $maxLng = $lng;
        }

        if (count($ring) < 3) continue;

        $landType = str_starts_with(mb_strtolower($parcelNumber), 'st.') 
            ? 'stavební parcela (zastavěná plocha)' 
            : 'pozemková parcela';


        $stmt->execute([
            ':cuzk_id'             => $cuzkId,
            ':parcel_number'       => $parcelNumber,
            ':cadastral_area_code' => $kuCode,
            ':cadastral_area_name' => $kuName,
            ':area_sqm'            => $areaSqm,
            ':land_type'           => $landType,
            ':min_lat'             => $minLat,
            ':max_lat'             => $maxLat,
            ':min_lng'             => $minLng,
            ':max_lng'             => $maxLng,
            ':center_lat'          => round(($minLat + $maxLat) / 2, 6),
            ':center_lng'          => round(($minLng + $maxLng) / 2, 6),
            ':geometry_json'       => json_encode(['type' => 'Polygon', 'coordinates' => [$ring]]),
        ]);

        $count++;
    }

    $pdo->commit();
    return $count;
}

$totalImported = 0;

foreach ($cadastralAreas as $code => $info) {
    $kuCode = (string)$code;
    echo " -> Zpracovávám KÚ {$info['name']} ({$kuCode})...\n";
    $imported = importFromCuzkInspire($kuCode, $info['name'], $pdo, $insertStmt);
    echo "    [OK - ČÚZK INSPIRE GML] Načteno {$imported} reálných parcel pro {$info['name']}.\n";
    $totalImported += $imported;
}

echo "\n=== Hotovo! Celkem uloženo {$totalImported} parcel do SQLite databáze. ===\n";