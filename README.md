### Mapa parcel - Okres Jičín

Webová aplikace pro zobrazení parcel v okrese Jičín (město Jičín a 4 okolní KÚ: Valdice, Holín, Staré Místo, Železnice). 

### Požadavky

* PHP 8.1+ s povolenými rozšířeními v `php.ini`: `pdo_sqlite`, `zip`, `curl`
* Webový prohlížeč Chrome nebo Firefox

### Spuštění aplikace

1. Stažení dat z ČÚZK a vytvoření SQLite databáze:
`php scripts/import.php`
2. Spuštění PHP serveru:
`php -S localhost:8000 -t public`
3. Otevření v prohlížeči:
http://localhost:8000

### Krátký zápisník

### Jaká jsem udělal rozhodnutí

* Data parcel jsem předem stáhl z ČÚZK INSPIRE do lokální SQLite databáze, aby se mapa nesekala čekáním na odpověď ze serveru ČÚZK při každém posunu.
* Při oddálení mapy pod zoom 16 se zobrazuje jen obrázková vrstva z ČÚZK WMS. Klikací parcely z databáze se načítají až od zoomu 16 a jen pro aktuálně viditelnou část obrazovky.
* Už načtené parcely zůstávají uložené v paměti prohlížeče, takže se při posunu mapy zpět nebo při filtrování nemusí stahovat znovu.
* Projekt jsem vytvářel ve spolupráci s AI, přičemž jsem vše průběžně testoval, ověřoval na reálných datech a upravoval, aby aplikace fungovala správně a plynule.

### Co mě překvapilo

* V souborech INSPIRE GML jsou k dispozici hranice, čísla parcel a výměra, ale ne podrobnější údaje z katastru. Parcely proto v mapě rozlišuji podle čísla na stavební s předponou `st.` a pozemkové, a do detailu jsem přidal tlačítko, které otevře konkrétní parcelu přímo v Nahlížení do KN a na Mapy.cz.
* Při kontrole mapy jsem narazil na to, že v KÚ Staré Místo se všechny parcely zobrazují jako pozemkové, protože toto území má v katastru jednotnou číselnou řadu a nepoužívá předponu `st.`.

### Co bych s více časem řešil jinak

* Doplnil bych všechna zbývající katastrální území v okrese Jičín.
* Nastavil bych automatickou aktualizaci databáze (např. 1× denně v noci přes cron).
* Propojil bych data s RÚIAN pro zobrazení přesného druhu pozemku a upravil rozhraní pro mobilní zařízení.