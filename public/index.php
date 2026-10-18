<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/ParcelRepository.php';

use App\Database;
use App\ParcelRepository;

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// Obsluha statických souborů pro vestavěný PHP server (php -S)
if ($uri !== '/' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}

// REST API Endpointy
if (str_starts_with($uri, '/api/')) {
    @ob_start('ob_gzhandler');
    header('Content-Type: application/json; charset=utf-8');

    $dbPath = __DIR__ . '/../data/database.sqlite';
    if (!file_exists($dbPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Databáze neexistuje. Spusťte nejprve: php scripts/import.php']);
        exit;
    }

    try {
        $db = new Database($dbPath);
        $repo = new ParcelRepository($db->getConnection());

        // 1. Metadata pro filtry a statistiky
        if ($uri === '/api/meta') {
            echo json_encode($repo->getMetadata(), JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 2. Načtení parcel v aktuálním výřezu mapy (Bounding Box)
        if ($uri === '/api/parcels') {
            $minLat = (float)($_GET['minLat'] ?? 0);
            $maxLat = (float)($_GET['maxLat'] ?? 0);
            $minLng = (float)($_GET['minLng'] ?? 0);
            $maxLng = (float)($_GET['maxLng'] ?? 0);
            $ku     = $_GET['ku'] ?? null;
            $type   = $_GET['type'] ?? null;

            $rows = $repo->findInBoundingBox($minLat, $maxLat, $minLng, $maxLng, $ku, $type, 25000);

            // Transformace do standardu GeoJSON FeatureCollection
            $features = [];
            foreach ($rows as $row) {
                $features[] = [
                    'type' => 'Feature',
                    'id' => (int)$row['id'],
                    'properties' => [
                        'id' => (int)$row['id'],
                        'cuzk_id' => $row['cuzk_id'],
                        'parcel_number' => $row['parcel_number'],
                        'cadastral_area_code' => $row['cadastral_area_code'],
                        'cadastral_area_name' => $row['cadastral_area_name'],
                        'area_sqm' => (int)$row['area_sqm'],
                        'land_type' => $row['land_type'],
                    ],
                    'geometry' => json_decode($row['geometry_json'], true),
                ];
            }

            echo json_encode([
                'type' => 'FeatureCollection',
                'count' => count($features),
                'features' => $features,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 3. Vyhledávání parcely podle čísla
        if ($uri === '/api/search') {
            $q = trim((string)($_GET['q'] ?? ''));
            $ku = $_GET['ku'] ?? null;
            if ($q === '') {
                echo json_encode([]);
                exit;
            }
            echo json_encode($repo->searchByNumber($q, $ku), JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 4. Detail konkrétní parcely (např. /api/parcels/123)
        if (preg_match('#^/api/parcels/(\d+)$#', $uri, $matches)) {
            $parcel = $repo->findById((int)$matches[1]);
            if (!$parcel) {
                http_response_code(404);
                echo json_encode(['error' => 'Parcela nenalezena']);
                exit;
            }
            $parcel['geometry'] = json_decode($parcel['geometry_json'], true);
            unset($parcel['geometry_json']);
            
            echo json_encode($parcel, JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Endpoint nenalezen']);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mapa parcel – Okres Jičín</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        #map { height: calc(100vh - 64px); width: 100%; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 overflow-hidden">

    <!-- Horní navigační a filtrační lišta -->
    <header style="z-index: 2000;" class="h-16 bg-white border-b border-slate-200 px-4 flex items-center justify-between shadow-sm relative">
        <div class="flex items-center gap-3">
            <div class="bg-emerald-600 text-white font-bold px-3 py-1.5 rounded-lg text-sm tracking-wide">
                KN JIČÍN
            </div>
            <div>
                <h1 class="font-bold text-slate-900 leading-tight">Katastrální mapa parcel</h1>
                <p class="text-xs text-slate-500">Okres Jičín • ČÚZK INSPIRE + WMS</p>
            </div>
        </div>

        <!-- Filtry a vyhledávání -->
        <div class="flex items-center gap-3">
            <!-- Výběr katastrálního území -->
            <select id="kuFilter" class="bg-slate-100 border border-slate-300 text-sm rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                <option value="">Všechna načtená KÚ (Okres Jičín)</option>
            </select>

            <!-- Filtr druhu pozemku -->
            <select id="typeFilter" class="bg-slate-100 border border-slate-300 text-sm rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                <option value="">Všechny druhy pozemků</option>
            </select>

            <!-- Rychlé hledání podle parcelního čísla -->
            <div class="relative">
                <input id="searchInput" type="text" placeholder="Hledat parcelu (např. 120)..." 
                    class="bg-slate-100 border border-slate-300 text-sm rounded-lg px-3 py-1.5 w-52 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                <div id="searchResults" class="hidden absolute right-0 mt-1 w-72 bg-white border border-slate-200 rounded-lg shadow-xl max-h-64 overflow-y-auto z-50"></div>
            </div>

            <!-- Indikátor stavu a počtu vykreslených parcel -->
            <div id="statusBadge" class="text-xs font-medium bg-slate-100 text-slate-700 px-3 py-1.5 rounded-lg border border-slate-200 min-w-[140px] text-center">
                Načítání mapy...
            </div>
        </div>
    </header>

    <!-- Hlavní oblast: Mapa + Boční panel s detailem -->
    <div class="relative flex">
        <div id="map"></div>

        <!-- Boční panel pro detail vybrané parcely -->
        <aside id="detailPanel" style="z-index: 1500;" class="hidden w-96 bg-white border-l border-slate-200 h-[calc(100vh-64px)] p-5 overflow-y-auto shadow-xl flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
                    <div>
                        <span class="text-xs uppercase tracking-wider font-semibold text-emerald-600">Detail pozemku</span>
                        <h2 id="detailTitle" class="text-xl font-bold text-slate-900">Parcela č. —</h2>
                    </div>
                    <button id="closeDetailBtn" class="text-slate-400 hover:text-slate-600 text-xl font-bold px-2">&times;</button>
                </div>

                <div class="space-y-3 text-sm">
                    <div class="bg-slate-50 p-3 rounded-lg border border-slate-100">
                        <div class="text-xs text-slate-400">Katastrální území</div>
                        <div id="detailKu" class="font-semibold text-slate-800 mt-0.5">—</div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-slate-50 p-3 rounded-lg border border-slate-100">
                            <div class="text-xs text-slate-400">Výměra</div>
                            <div id="detailArea" class="font-semibold text-slate-800 mt-0.5">— m²</div>
                        </div>
                        <div class="bg-slate-50 p-3 rounded-lg border border-slate-100">
                            <div class="text-xs text-slate-400">ID ČÚZK</div>
                            <div id="detailCuzkId" class="font-mono text-xs text-slate-700 mt-1 truncate">—</div>
                        </div>
                    </div>

                    <div class="bg-slate-50 p-3 rounded-lg border border-slate-100">
                        <div class="text-xs text-slate-400">Druh pozemku (evidence)</div>
                        <div id="detailType" class="font-semibold text-slate-800 mt-0.5">—</div>
                    </div>

                    <div class="bg-slate-50 p-3 rounded-lg border border-slate-100">
                        <div class="text-xs text-slate-400">GPS středu (WGS-84)</div>
                        <div id="detailGps" class="font-mono text-xs text-slate-700 mt-0.5">—</div>
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t border-slate-100 space-y-2">
                <a id="cuzkLink" href="#" target="_blank" rel="noopener noreferrer"
                   class="block w-full text-center bg-emerald-600 hover:bg-emerald-700 text-white font-medium text-sm py-2.5 px-4 rounded-lg transition">
                    Otevřít v Nahlížení do KN (ČÚZK) ↗
                </a>
                <a id="mapyCzLink" href="#" target="_blank" rel="noopener noreferrer"
                   class="block w-full text-center bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium text-sm py-2 px-4 rounded-lg transition">
                    Zobrazit na Mapy.cz ↗
                </a>
            </div>
        </aside>
    </div>

    <script src="/app.js"></script>
</body>
</html>