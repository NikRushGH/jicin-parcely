# Mapa parcel - Okres Jičín

Interaktivní webová aplikace pro vizualizaci a vyhledávání katastrálních parcel v okrese Jičín (město Jičín a okolní katastrální území: Valdice, Holín, Staré Místo, Železnice) nad otevřenými daty ČÚZK.

## Požadavky

- PHP 8.1+ s povolenými rozšířeními v `php.ini`: `pdo_sqlite`, `zip`, `curl`
- Moderní webový prohlížeč (Chrome, Firefox, Edge)

## Spuštění aplikace

1. Stažení dat z ČÚZK INSPIRE a inicializace SQLite databáze:
```bash
php scripts/import.php
```

2. Spuštění vestavěného PHP serveru:
```bash
php -S localhost:8000 -t public
```


3. Otevření aplikace v prohlížeči:
`http://localhost:8000`

## Architektura a technické řešení

* **Lokální SQLite databáze a BBox indexace:** Data parcel (hranice polygonů, parcelní čísla a výměry) se stahují z ČÚZK INSPIRE (formát GML v souřadnicovém systému ETRS89) do lokální SQLite databáze v režimu WAL. Tím je zajištěna okamžitá odezva API bez čekání na externí servery ČÚZK při pohybu mapou.
* **Kombinace WMS a vektorové vrstvy:** Při oddálení mapy pod zoom 16 se zobrazuje rastrová vrstva z ČÚZK WMS. Interaktivní vektorové parcely z databáze se načítají od zoomu 16 pouze pro aktuálně viditelný výřez obrazovky (Bounding Box) a vykreslují se přes HTML5 Canvas.
* **Klientská cache v prohlížeči:** Již načtené bloky mapy zůstávají uložené v paměti klienta (`Map` / `Set`), takže se při posunu mapy zpět nebo při přepínání filtrů nemusí stahovat ze serveru znovu.
* **Klasifikace a napojení na katastr:** Protože soubory INSPIRE GML obsahují geometrii, čísla parcel a výměru bez podrobnějších údajů z RÚIAN, aplikace rozlišuje parcely podle označení na stavební (předpona `st.`) a pozemkové (s výjimkou KÚ s jednotnou číselnou řadou, jako je Staré Místo). V detailu parcely je přímé prokliknutí na konkrétní pozemek v portálu Nahlížení do KN a na Mapy.cz.

## Možnosti dalšího rozšíření

* Doplnění zbývajících katastrálních území v celém okrese Jičín.
* Automatická aktualizace databáze pomocí plánované úlohy (cron).
* Propojení s daty RÚIAN pro zobrazení přesného druhu pozemku a způsobu využití.

