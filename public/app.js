const INTERACTIVE_ZOOM = 16; // Minimální zoom pro načítání interaktivních parcel

// Inicializace mapy se zapnutým HTML5 Canvas rendererem pro maximální výkon při tisících polygonech 
const map = L.map('map', {
    center: [50.4372, 15.3517], // Střed města Jičín
    zoom: INTERACTIVE_ZOOM,
    preferCanvas: true
});

// 1. Základní podkladová mapa OpenStreetMap
const osmLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 20,
    attribution: '&copy; OpenStreetMap přispěvatelé | Data: ČÚZK'
}).addTo(map);

// 2. Oficiální ČÚZK WMS vrstva katastrální mapy (pokrývá celý okres Jičín bez zátěže prohlížeče)
const cuzkWmsLayer = L.tileLayer.wms('https://services.cuzk' + '.gov.cz/wms/local-km-wms.asp', {
    layers: 'KN',
    format: 'image/png',
    transparent: true,
    maxZoom: 20,
    minZoom: 12,
    opacity: 0.65,
    attribution: '&copy; ČÚZK WMS'
}).addTo(map);

// 3. Vektorová vrstva pro interaktivní parcely z naší SQLite databáze
let geoJsonLayer = L.geoJSON(null, {
    style: (feature) => getParcelStyle(feature, feature.properties.id === selectedParcelId),
    onEachFeature: (feature, layer) => {
        layer.on({
            mouseover: (e) => {
                if (selectedParcelId !== feature.properties.id) {
                    e.target.setStyle({ weight: 2, fillOpacity: 0.45 });
                }
            },
            mouseout: (e) => {
                if (selectedParcelId !== feature.properties.id) {
                    e.target.setStyle(getParcelStyle(feature, false));
                }
            },
            click: (e) => {
                L.DomEvent.stopPropagation(e);
                selectParcel(feature.properties.id, e.target);
            }
        });
    }
}).addTo(map);

let selectedParcelId = null;
let cadastralAreasMeta = [];
let fetchAbortController = null;
const loadedFeatures = new Map(); // Všechny již stažené parcely v paměti klienta (id -> feature)
const renderedIds = new Set();    // ID parcel, které jsou aktuálně vykreslené v mapě
const fetchedBboxes = new Set();  // Již stažené čtverce mapy, abychom je netahali znovu


// Barevné rozlišení podle kategorie parcely (stavební vs. pozemková)
function getParcelStyle(feature, isSelected) {
    if (isSelected) {
        return { color: '#b91c1c', weight: 3, fillColor: '#ef4444', fillOpacity: 0.6 };
    }
    const type = feature.properties.land_type || '';
    const isBuilding = type.includes('stavební');

    return {
        color: isBuilding ? '#d97706' : '#0284c7',
        weight: 1.5,
        fillColor: isBuilding ? '#f59e0b' : '#38bdf8',
        fillOpacity: 0.38
    };

}


// Načtení metadat (seznam KÚ a druhů pozemků) při startu
async function loadMetadata() {
    const res = await fetch('/api/meta');
    const data = await res.json();

    cadastralAreasMeta = data.cadastral_areas || [];
    const kuSelect = document.getElementById('kuFilter');
    cadastralAreasMeta.forEach(ku => {
        const opt = document.createElement('option');
        opt.value = ku.code;
        opt.textContent = `${ku.name} (${ku.parcel_count} parcel)`;
        kuSelect.appendChild(opt);
    });

    const typeSelect = document.getElementById('typeFilter');
    (data.land_types || []).forEach(type => {
        const opt = document.createElement('option');
        opt.value = type;
        opt.textContent = type;
        typeSelect.appendChild(opt);
    });
}

// Pomocná funkce pro překreslení vrstvy podle aktuálních filtrů z již stažených dat v paměti
function refreshRenderedParcels() {
    const ku = document.getElementById('kuFilter').value;
    const type = document.getElementById('typeFilter').value;

    geoJsonLayer.clearLayers();
    renderedIds.clear();

    const matching = [];
    for (const feature of loadedFeatures.values()) {
        const p = feature.properties;
        if (ku && String(p.cadastral_area_code) !== String(ku)) continue;
        if (type && p.land_type !== type) continue;
        matching.push(feature);
        renderedIds.add(p.id);
    }

    if (matching.length > 0) {
        geoJsonLayer.addData({ type: 'FeatureCollection', features: matching });
    }
}

// Načtení parcel v aktuálním výřezu mapy (stažené parcely zůstávají v paměti klienta)
async function loadParcelsInView() {
    const statusBadge = document.getElementById('statusBadge');
    const zoom = map.getZoom();

    // Při oddálení pod zoom INTERACTIVE_ZOOM skryjeme vektory a necháme ČÚZK WMS
    if (zoom < INTERACTIVE_ZOOM) {
        cuzkWmsLayer.setOpacity(0.65);
        geoJsonLayer.clearLayers();
        renderedIds.clear();
        statusBadge.textContent = `Přibližte mapu pro interaktivní parcely (zoom ${INTERACTIVE_ZOOM}+)`;
        statusBadge.className = 'text-xs font-medium bg-amber-50 text-amber-800 px-3 py-1.5 rounded-lg border border-amber-200';
        return;
    }

    const bounds = map.getBounds();
    const ku = document.getElementById('kuFilter').value;
    const type = document.getElementById('typeFilter').value;
    cuzkWmsLayer.setOpacity(type ? 0.2 : 0.65);

    // Pokud jsme se vrátili ze vzdáleného zoomu a v paměti už máme parcely, hned je ukážeme
    if (renderedIds.size === 0 && loadedFeatures.size > 0) {
        refreshRenderedParcels();
    }

    // Zaokrouhlení BBoxu směrem ven na ~1 km bloky
    const minLat = (Math.floor(bounds.getSouth() * 100) / 100).toFixed(2);
    const maxLat = (Math.ceil(bounds.getNorth() * 100) / 100).toFixed(2);
    const minLng = (Math.floor(bounds.getWest() * 100) / 100).toFixed(2);
    const maxLng = (Math.ceil(bounds.getEast() * 100) / 100).toFixed(2);

    const bboxKey = `${minLat}_${maxLat}_${minLng}_${maxLng}`;

    // Pokud už tento blok mapy máme stažený v paměti, nic ze serveru nestahujeme!
    if (fetchedBboxes.has(bboxKey)) {
        statusBadge.textContent = `V paměti: ${renderedIds.size} parcel (cache)`;
        statusBadge.className = 'text-xs font-medium bg-emerald-50 text-emerald-800 px-3 py-1.5 rounded-lg border border-emerald-200';
        return;
    }

    if (fetchAbortController) {
        fetchAbortController.abort();
    }
    fetchAbortController = new AbortController();

    statusBadge.textContent = 'Načítám parcely...';

    try {
        const params = new URLSearchParams({ minLat, maxLat, minLng, maxLng });
        const res = await fetch(`/api/parcels?${params.toString()}`, {
            signal: fetchAbortController.signal
        });
        const geojson = await res.json();

        fetchedBboxes.add(bboxKey);

        // Přidáme do paměti a na mapu POUZE ty parcely, které tam ještě nejsou
        const newFeatures = [];
        for (const feature of (geojson.features || [])) {
            const id = feature.properties.id;
            loadedFeatures.set(id, feature);

            if (!renderedIds.has(id)) {
                if (ku && String(feature.properties.cadastral_area_code) !== String(ku)) continue;
                if (type && feature.properties.land_type !== type) continue;
                newFeatures.push(feature);
                renderedIds.add(id);
            }
        }

        if (newFeatures.length > 0) {
            geoJsonLayer.addData({ type: 'FeatureCollection', features: newFeatures });
        }

        statusBadge.textContent = `V paměti: ${renderedIds.size} parcel`;
        statusBadge.className = 'text-xs font-medium bg-emerald-50 text-emerald-800 px-3 py-1.5 rounded-lg border border-emerald-200';
    } catch (err) {
        if (err.name !== 'AbortError') {
            console.error(err);
        }
    }
}



// Zobrazení detailu parcely v pravém panelu po kliknutí
async function selectParcel(id, layer) {
    selectedParcelId = id;
    geoJsonLayer.resetStyle();
    if (layer) {
        layer.setStyle(getParcelStyle(layer.feature, true));
    }

    const res = await fetch(`/api/parcels/${id}`);
    const p = await res.json();

    document.getElementById('detailTitle').textContent = `Parcela č. ${p.parcel_number}`;
    document.getElementById('detailKu').textContent = `${p.cadastral_area_name} (${p.cadastral_area_code})`;
    document.getElementById('detailArea').textContent = `${Number(p.area_sqm).toLocaleString('cs-CZ')} m²`;
    document.getElementById('detailCuzkId').textContent = p.cuzk_id;
    document.getElementById('detailType').textContent = p.land_type || 'Neuvedeno';
    document.getElementById('detailGps').textContent = `${p.center_lat}N, ${p.center_lng}E`;

    // Odkaz do Nahlížení do KN a Mapy.cz
    const cleanId = String(p.cuzk_id || '').replace(/\D+/g, '');
    document.getElementById('cuzkLink').href = 
        'https://sgi-nahlizenidokn' + '.cuzk.gov.cz' + 
        '/marushka/default.aspx?themeid=3&MarWindowName=Marushka&MarQueryId=2EDA9E08&MarQParam0=' + 
        cleanId + '&MarQParamCount=1';


    document.getElementById('mapyCzLink').href = `https://mapy.cz/zakladni?x=${p.center_lng}&y=${p.center_lat}&z=18&source=coor&id=${p.center_lng}%2C${p.center_lat}`;

    document.getElementById('detailPanel').classList.remove('hidden');
}

// Události mapy a ovládacích prvků
map.on('moveend', loadParcelsInView);

document.getElementById('closeDetailBtn').addEventListener('click', () => {
    document.getElementById('detailPanel').classList.add('hidden');
    selectedParcelId = null;
    geoJsonLayer.resetStyle();
});


document.getElementById('kuFilter').addEventListener('change', (e) => {
    refreshRenderedParcels();
    const code = e.target.value;
    const found = cadastralAreasMeta.find(k => String(k.code) === String(code));
    if (found && found.lat && found.lng) {
        map.setView([found.lat, found.lng], INTERACTIVE_ZOOM);
    } else {
        loadParcelsInView();
    }
});

document.getElementById('typeFilter').addEventListener('change', () => {
    refreshRenderedParcels();
    loadParcelsInView();
});


// Vyhledávání podle parcelního čísla
let searchTimeout = null;
const searchInput = document.getElementById('searchInput');
const searchResults = document.getElementById('searchResults');

searchInput.addEventListener('input', () => {
    clearTimeout(searchTimeout);
    const q = searchInput.value.trim();
    if (q.length < 1) {
        searchResults.classList.add('hidden');
        return;
    }

    searchTimeout = setTimeout(async () => {
        const ku = document.getElementById('kuFilter').value;
        const res = await fetch(`/api/search?q=${encodeURIComponent(q)}&ku=${encodeURIComponent(ku)}`);
        const items = await res.json();

        searchResults.innerHTML = '';
        if (items.length === 0) {
            searchResults.innerHTML = '<div class="p-3 text-xs text-slate-400">Žádná parcela nenalezena</div>';
        } else {
            items.forEach(item => {
                const div = document.createElement('div');
                div.className = 'p-2.5 hover:bg-emerald-50 cursor-pointer border-b border-slate-100 text-xs flex justify-between items-center';
                div.innerHTML = `
                    <div>
                        <span class="font-bold text-slate-800">Parc. č. ${item.parcel_number}</span>
                        <span class="text-slate-500 block">${item.cadastral_area_name}</span>
                    </div>
                    <span class="text-slate-400">${item.area_sqm} m²</span>
                `;
                div.addEventListener('click', () => {
                    searchResults.classList.add('hidden');
                    map.setView([item.center_lat, item.center_lng], 18);
                    selectParcel(item.id, null);
                });
                searchResults.appendChild(div);
            });
        }
        searchResults.classList.remove('hidden');
    }, 250);
});

// Start aplikace
loadMetadata().then(loadParcelsInView);