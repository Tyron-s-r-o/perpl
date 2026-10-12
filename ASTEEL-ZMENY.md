# Zmeny potrebné v asteel.sk po úpravách forku Perpl

Stav k 2026-10-12. Určené pre agenta, ktorý bude asteel.sk upravovať. V asteel.sk som **nič nemenil**; fork (`/Users/richard.vaclavik/Sites/perpl-tyron/perpl`, vetva `release-2.9.1`) má zmeny necommitnuté v pracovnom strome.

Značky: **[M]** = overené spustením, **[A]** = spočítané grepom, **[?]** = neoverené.

## 1. Povinné kroky pri prechode na upravený fork

1. **Fork je vydaný ako `v3.0.0`** (vetva `release-3.0`). V asteel na skúšobnej vetve zmeniť v `composer.json` `"perplorm/perpl": "^2.9"` na `"^3.0"` a pustiť `composer update perplorm/perpl`. Fork vyžaduje `php >=8.3`. Produkcia s `^2.9` ostáva na 2.9.1.
2. **Pregenerovať modely:** `php8.4 vendor/bin/propel build`. Zmení sa všetkých 1 204 súborov v `Base/` a `Map/`; ručne písané stuby sa negenerujú. Staré vygenerované triedy sa s novým runtime načítajú a hydratujú správne, ale `(string)$model` na nich spadne (majú v sebe formát YAML, ktorý fork už nevie) — pregenerovanie je preto povinné v tom istom kroku ako update forku.
3. **Opraviť 4 volania zrušených query metód** (kapitola 2).
4. **Zmazať mŕtve generované triedy** (kapitola 3) — nie je to podmienka, ale dnes padajú na fatal pri načítaní.

Cache (`SimpleCache`) **netreba mazať**: nový kód číta staré serializované modely a starý kód číta nové. Overené na 1 210 objektoch z 300 modelov v oboch smeroch. **[M]**

## 2. Zrušené query metódy

Generátor už nevytvára `withXQuery()`, `useXExistsQuery()`, `useXNotExistsQuery()`, `useInXQuery()`, `useNotInXQuery()`. Generické metódy v runtime ostávajú. V asteel sú 4 volania **[M]**:

| Súbor | Riadok | Teraz | Nahradiť za |
|---|---|---|---|
| `api/modul/Custom/Controller/NotificationApiController.php` | 2209, 2220, 2468, 2479 | `->useOrderAddressRelatedByIdZvozAddressNotExistsQuery()` | `->useNotExistsQuery('OrderAddressRelatedByIdZvozAddress')` |

`app/lib/VendorItem/ItemCommerce/Loader/OrderSaleLoader.php:293` už používa generické `useExistsQuery('OrderTracking')` a meniť ho netreba.

Náhrady pre ostatné varianty, ak by sa niekde objavili:

- `useXExistsQuery()` → `useExistsQuery('X')`
- `useInXQuery()` → `useInQuery('X')`, `useNotInXQuery()` → `useNotInQuery('X')`
- `withXQuery(fn ($q) => …)` → `useXQuery()` … `->endUse()`

## 3. Mŕtve generované triedy

47 súborov v `app/lib/VendorItem/**/Base` a `**/Map` patrí tabuľkám, ktoré už v schéme nie sú. Sú vo formáte starého Propelu a pri načítaní padajú na fatal — spolu so svojimi stubmi 254 tried. **[M]**

Modely (každý má `Base/X.php`, `Base/XQuery.php`, `Map/XTableMap.php` a stuby o úroveň vyššie):

- `Item/Item`: FileTag, FormAttachements, FormMessages, FormText, LayoutMetaTag, MetaTagLayout, MetaTagNew, MetaTagPage, MetaTagProduct, PageHolderItem, PageMetaTagPage, PageTag, WidgetSearch
- `ItemCommerce/Item`: ProductManufacturerPriceLevel, ProductVariant, ProductVariantVariant
- `ItemWidget/Item`: WidgetProductMenu

Pred zmazaním overiť grepom, že ich aplikácia nikde nepoužíva. `WidgetSearch` má v stube vlastný kód (`app/lib/VendorItem/ItemWidget/Item/WidgetSearch.php:24`). **[?]**

Presný zoznam vráti kontrola z kapitoly 7 (riadok `only in consumer`).

## 4. Zmeny správania, ktoré treba prejsť v kóde

### 4.1 `serialize($model)` už nemaže relácie zo živého objektu

Doteraz generované `__sleep()` volalo `clearAllReferences()` na objekte, ktorý sa serializoval. Po `serialize()` teda objekt stratil načítané relácie a ďalší `getXxxs()` išiel znova do DB. Teraz ostane objekt nezmenený. Serializovaná podoba je rovnaká ako predtým: stĺpce a stav, bez relácií.

Miesta, kde sa model serializuje a potom sa s ním ďalej pracuje **[A]** — skontrolovať, či kód nespolieha na to, že sa relácie po serializácii načítajú nanovo:

- `app/lib/VendorItem/ItemCommerce/Item/Client.php:785` (`postSave`, `serialize($this)`)
- `app/middleware/Authentication.php:173` (`$this->user`)
- `app/middleware/WebClientAuth.php:93` (`$this->client`)
- `app/lib/VendorItem/ItemCommerce/Traits/OrderCacheTrait.php:37,59` (kolekcie `OrderProduct`)
- `app/lib/VendorItem/ItemCommerce/Loader/UserLoader.php:45`
- `app/lib/Widget/ProductList.php:912`
- `app/lib/VendorItem/ItemCommerce/Traits/OrderDocumentTrait.php:163`

`OrderCacheTrait` ukladá cache s názvom naznačujúcim pripojené produkty, ale pripojené objekty sa neserializovali ani predtým a neserializujú sa ani teraz. Ak ich tam chcete, treba to riešiť v aplikácii. **[A — odvodené z kódu]**

### 4.2 Interné properties modelov

Zmizli `collXxxPartial` a `xxxScheduledForDeletion` (po dvoch na každú 1:n reláciu). Nahrádzajú ich dve polia `partialFlagByCollection` a `deletionsByCollection`. Stuby v asteel na ne priamo nesiahajú (0 výskytov), takže zmena nie je potrebná. **[M]**

### 4.3 `condition()` a `combine()`

Sú to už riadne metódy `Criteria`, nie `__call` s deprecation hláškou. Volania (351 v asteel) ostávajú bez zmeny, len sú o 4–9 % rýchlejšie.

### 4.3a `(string)$model` vracia JSON namiesto YAML

Predvolený `defaultStringFormat` je vo forku `JSON` a YAML fork už nepodporuje. Mení sa len výstup `(string)$model` a `(string)$kolekcia`; `exportTo('JSON')`, `toArray()`, `serialize()` ostávajú. V asteel som grepom nenašiel miesto, kde sa model alebo kolekcia prevádza na reťazec; nepriamy prevod v šablónach alebo logoch grep spoľahlivo nenájde. **[A]**

### 4.4 Čo z forku zmizlo (asteel to podľa grepu nepoužíva)

- Podpora PostgreSQL, Oracle a MSSQL; behaviory okrem `timestampable` a `archivable`; validácia cez `symfony/validator`.
- Príkazy `init`, `reverse`, `graphviz`, `datadictionary:export`, `config:preview`. Ostali `build`, `diff`, `migrate`, `sql:build`, `sql:insert`, `config:convert` a `migration:*`.
- Konfigurácia v YAML/JSON/INI/XML — fork číta len `propel.php`. asteel má `propel.php`, takže bez zmeny. **[M]**
- `exportTo`/`importFrom`/`toXML`/`toCSV`/`toYAML` pre XML, CSV a YAML. asteel volá len `exportTo('JSON')`. **[A]**

## 5. Nálezy v asteel.sk, ktoré s forkom nesúvisia

- **Schéma je pred modelmi:** stĺpec `product.rating` je v `commerce.schema.xml`, ale `Base/Product.php`, `Base/ProductQuery.php` a `Map/ProductTableMap.php` ho nemajú. Pregenerovanie to opraví. **[M]**
- **`order_product_backup`:** `SELECT` cez model `ItemCommerce\Backup\OrderProductBackup` padá na SQL chybe (na pôvodnom aj upravenom ORM) — tabuľka v backup DB nezodpovedá schéme. **[M]**
- **`public/index.php:9`** volá `Perpl::disableInstancePooling()` až po `Application::run()`, teda bez účinku. **[M]**
- **Xdebug v CLI** je lokálne zapnutý v móde `profile`; skripty púšťané z CLI sú kvôli tomu asi 2× pomalšie. Pre merania použiť `-d xdebug.mode=off`. **[M]**

## 5a. Prečo `propel diff` generuje nesúvisiace SQL

Pustil som `diff` pôvodným forkom 2.9.1 proti kópii lokálnej DB bez akejkoľvek zmeny schémy. Navrhol zmeny v 7 tabuľkách + 1 v backup DB. Takmer všetko sú **skutočné rozdiely medzi DB a `schema.xml`**, nie chyba generátora. Kým sa neodstránia, budú sa objavovať v každej migrácii. **[M — lokálna DB; produkčnú som nevidel]**

| Tabuľka | Čo `diff` navrhuje | Príčina | Oprava |
|---|---|---|---|
| `eshop_saved_item` | `sent_at`, `ordered_at` → `TIMESTAMP NULL`; `DROP note` | V DB sú `datetime`, v schéme `TIMESTAMP`. Stĺpec `note` je v DB, v schéme nie. | V schéme `type="DATETIME"`; doplniť alebo zmazať `note`. |
| `heureka_price_list` | `in_effect_from` → `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` | V DB `datetime NOT NULL` bez defaultu, v schéme `TIMESTAMP required`. Platforma navyše k `NOT NULL TIMESTAMP` bez defaultu sama pridá `DEFAULT CURRENT_TIMESTAMP`. | V schéme `type="DATETIME"`. |
| `dynamic_landing_page` | `created_at`, `updated_at` → `TIMESTAMP NULL` | V DB majú `DEFAULT CURRENT_TIMESTAMP` (a `ON UPDATE`), v schéme sú to stĺpce z `timestampable` bez defaultu. | Zosúladiť DB so schémou (zrušiť defaulty) alebo ich v schéme zapísať explicitne. |
| `status_prediction_log` | iné názvy 4 indexov; `is_override` → `TINYINT`; `features` → `TEXT` | Tabuľka vznikla ručne: indexy sa volajú `idx_*`, schéma má `spl_*_idx`. `is_override` je `tinyint(1)` (= BOOLEAN), schéma má `TINYINT`. `features` je `longtext`, schéma `LONGVARCHAR`. | V schéme `type="BOOLEAN"`, `sqlType="LONGTEXT"` a názvy indexov podľa DB. |
| `warehouse_stock_action` | `positions_changed` → `TINYINT DEFAULT 0 NOT NULL` | V DB `tinyint`, schéma má `BOOLEAN` (= `tinyint(1)`). | V DB `MODIFY … TINYINT(1)`, alebo v schéme `TINYINT`. |
| `product_price` | `ADD CONSTRAINT` × 4 | Cudzie kľúče sú v schéme, v DB chýbajú. | Rozhodnúť, či ich chcete; podľa toho DB alebo schému. |
| `product_sale_price` | `DROP` FK, 3 indexy a stĺpce `id_country`, `source`, `computed_reason` | DB je pred schémou — stĺpce a indexy v `schema.xml` nie sú. | Doplniť do schémy. **Pozor: takto vygenerovaná migrácia by ich zmazala.** |
| `tmp_gls_merge_backup` | `DROP TABLE` | Dočasná tabuľka v DB. | Zmazať ju, alebo ju vylúčiť: `propel diff --skip-tables=tmp_gls_merge_backup`, prípadne kľúč `exclude_tables` v `propel.php`. |
| backup: `order_product_backup` | `ADD recycling_fee` | Stĺpec je v schéme, v backup DB chýba (preto padá `SELECT` z kapitoly 5). | Pustiť migráciu na backup DB. |

Postup: zosúladiť schému s DB pre tieto tabuľky, pustiť `diff` znova a pokračovať, kým nevráti prázdnu migráciu. Až potom sa dá výstupu `diff` veriť bez ručného čistenia.

## 6. Výkon — čo sa dá získať v aplikácii

Merané na kópii DB (`asteel_sk_perpl`), PHP 8.4, opcache zapnutá, Xdebug vypnutý. **[M]**

- **`paginate()` robí pri každom volaní `COUNT(*)`.** 20 strán po 50 riadkoch: `Product` 360 ms, `Order` 556 ms, `OrderProduct` 1 380 ms — takmer celý čas je COUNT nad veľkou tabuľkou. Kde stránka nezobrazuje celkový počet, stačí `->limit($n + 1)->offset(…)`. V asteel je 48 volaní `paginate`.
- **Instance pool je vypnutý na 92 miestach v 59 súboroch.** S vypnutým poolom ide každé `findPk()` do DB (35–50 µs na lokálnej DB). Miesta s `findPk`/`findOne` v cykle **[A]**: `api/modul/Api/Controller/ContentResponse/MobileProvider.php:906`, `RegularOrderStrategy.php:165`, `AjaxController.php:1223,1417`, `SatisfactionSurveyController.php:129`, `ClientPropertyMoveController.php:129`, `ProductAndroidController.php:152,176,226,283`.
- **Opcache:** generované modely zaberajú po pregenerovaní ~99 MB (predtým ~110 MB). Skontrolovať `opcache.memory_consumption` na produkcii. **[?]**

## 7. Ako overiť

Všetky tri skripty sú vo forku v `tests/consumer/` a do asteel.sk nezapisujú.

```bash
cd /Users/richard.vaclavik/Sites/perpl-tyron/perpl
A=/Users/richard.vaclavik/Sites/ASTEEL/asteel.sk

# 1. build modelov týmto forkom + načítanie všetkých tried + SQL pre každú tabuľku a reláciu
php8.4 -d xdebug.mode=off tests/consumer/check-consumer.php $A \
  --load=app/modul/Web/VendorItem --load=app/modul/Asteel/VendorItem \
  --load=app/modul/Custom/VendorItem --load=app/lib/Classes/ObjectPropelFormatter.php

# 2. reálne dáta: 50 riadkov z každej tabuľky cez modely, porovnanie s iným stavom ORM
export DB_PASSWORD=…   # heslo z asteel .env
php8.4 -d xdebug.mode=off tests/consumer/data-consumer.php dump $A /tmp/data.ser \
  --db=default:asteel_sk_perpl --db=backup:asteel_sk_perpl_backup --user=richard

# 3. benchmark (zápisy idú v transakcii, ktorá sa rollbackne)
php8.4 -d xdebug.mode=off -d opcache.enable_cli=1 tests/consumer/bench-consumer.php $A \
  --db=default:asteel_sk_perpl --db=backup:asteel_sk_perpl_backup --user=richard \
  --model='ItemCommerce\Item\Product' --model='ItemCommerce\Item\Order' --writes
```

Vlastné PHPUnit testy asteelu som **nepúšťal**. Dajú sa pustiť proti kópii DB nastavením `DB_NAME=asteel_sk_perpl` (konfigurácia číta `$_ENV`/`getenv`). **[?]**

## 8. Testovacia databáza

Na lokálnom MySQL som vytvoril `asteel_sk_perpl` a `asteel_sk_perpl_backup` — kópie `asteel_sk` a `asteel_sk_backup`. Zdrojové DB som len čítal.

- Tabuľky `cron_jobs_log`, `user_activity`, `sortiment_report_daily`, `heureka_bid_position` obsahujú len posledných 100 000 riadkov, ostatné sú celé.
- Štruktúra je z `mysqldump --no-data` bez triggerov.
- Keď už nebudú treba: `DROP DATABASE asteel_sk_perpl; DROP DATABASE asteel_sk_perpl_backup;`
