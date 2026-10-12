# Perpl fork — analýza modernizácie

Stav k 2026-10-12, vetva `release-2.9.1` (upstream 2.9.0 + 1 vlastný commit). Jediný konzument forku: asteel.sk.

Značky: **[M]** = sám som zmeral alebo prečítal v kóde, **[A]** = spočítané agentom cez grep (približné), **[?]** = neoverené / hypotéza.

## 1. Zhrnutie

- Syntakticky kód **nie je starý** — upstream ho už prečistil (902× `#[\Override]`, 85 % funkcií má návratový typ, žiadne `call_user_func`, `eval`, `@`, implicitne nullable parametre). Rector by pre PHP 8.3 zmenil 144 z ~500 súborov, väčšinou kozmeticky. **[M]**
- Skutočné archaizmy sú **štrukturálne**: 5 databáz, 19 behaviorov a 16 príkazov, z ktorých používate zlomok; globálny statický stav; dve paralelné implementácie filtrov; a hlavne **tvar generovaného kódu**.
- Najväčšia páka je generovaný kód: asteel má **1 252 súborov / 1,55 mil. riadkov** generovaného PHP, z toho 42 % komentáre. `Base/Product.php` má 17 969 riadkov, 192 properties a 628 public metód. **[M]**
- Najväčší reálny zisk pre pamäť/GC: instance pool (asteel ho na 92 miestach vypína), `__sleep` cez reflexiu (mení živý objekt) a 4,1 kB na prázdny `Product`. **[M]**
- Zhruba **25–30 % `src/`** (~27 000 z ~101 000 riadkov) je pre asteel mŕtva váha, ktorá sa dá zmazať bez dopadu na generovaný kód. **[A]**

## 2. Kto fork používa (`~/Sites`)

| Projekt | ORM | Verzia |
|---|---|---|
| ASTEEL/asteel.sk | **Tyron fork** | 2.9.1 (`1d3d48cda`) |
| hladamchatu/tyrion2 | upstream perplorm/perpl | 2.6.0 |
| damsky_klub.org/front | upstream perplorm/perpl | 2.4.0 |
| clean_cms, evil.sk, europe-state.front, mtech (2×), asteel_preprava, pneutesty.sk, tyron.sk/lovable, TyrionEshop, cosmee.eu | propel/propel | 2.0 beta3 / beta4 / dev |

- Fork používa naozaj **len asteel.sk**. **[M]**
- hladamchatu (184 tabuliek) a damsky_klub (53) používajú rovnakú podmnožinu ako asteel: MySQL, len `timestampable`, rovnaké typy stĺpcov. Fork ušitý na asteel im bude sedieť tiež. **[M]**
- V `~/Sites/propel-orm-8` je tvoj starší fork Propelu (`tyrion/propel-orm-8`), ktorý vyžaduje ~12 projektov — iná vetva vývoja, tu ju neriešim.

## 3. Čo asteel.sk reálne používa

### Schéma (11 súborov, 301 tabuliek, 2 928 stĺpcov, 557 FK) **[A]**

- **Typy:** INTEGER 1100, VARCHAR 738, BOOLEAN 300, LONGVARCHAR 210, DECIMAL 192, ENUM 109, TIMESTAMP 100, DATE 52, SMALLINT 34, CLOB 31, FLOAT 25, TIME 14, TINYINT 9, CHAR 4, BIGINT 3, DOUBLE 1, DATETIME 1.
- **Nepoužité typy:** SET, ARRAY, OBJECT, JSON, UUID, BLOB, VARBINARY.
- **Behaviory:** `timestampable` 145×, `archivable` 12× (archív ide do DB `backup`). Nič iné.
- **Kľúče:** 0 zložených PK, 0 zložených FK, 0 tabuliek bez PK, žiadne many-to-many (`isCrossRef`).
- **Nepoužité atribúty:** `lazyLoad`, `inheritance`, `phpType`, `primaryString`, `skipSql`, `readOnly`, `reloadOnInsert/Update`, `allowPkInsert`, `heavyIndexing`, `baseClass`, `tablePrefix`, `<external-schema>`, `<id-method-parameter>`.
- **Vendor:** len `Engine=InnoDB` (290×) a 1× FULLTEXT index. Žiadny charset ani collation.
- `generator.*` voľby nie sú nastavené žiadne — všetko beží na defaultoch.

### Runtime API **[A]**

| Oblasť | Používa sa | Nepoužíva sa |
|---|---|---|
| Ukončenie query | `find` ~1590, `findPk` ~690, `findOne` ~710, `findOneByX` 281, `exists` 147, `findPks` 139, `findByX` 102, `paginate` 48, `update` 23 | `findOneOrCreate`, `requireOne*`, `deleteAll` |
| Stavba query | `useXQuery` 913, `select` 764, `orderByX` 352, **`combine` 256, `_or` 254**, `distinct` 243, `addAsColumn` 165, `limit` 142, **`condition` 95**, `where` 51, `joinWithX` ~60 | `_if/_elseif`, `keepQuery`, `setQueryKey`, `prune`, `addSelectQuery`, `forUpdate`, `with()`, `populateRelation` |
| Výsledky | `getData` ~1790, `getFirst` 190, `toArray` ~300 | `setFormatter`, OnDemand, `toKeyValue`, `populateRelation`, collection `save/delete` |
| Model | `save` 1238, `isNew` ~780, virtuálne stĺpce 424, `getByName/setByName` ~175, `fromArray` ~150, `copy` ~32, `exportTo('JSON')` 4 | `importFrom`, `toXML/YAML/CSV`, `hashCode`, `equals`, `validate` (Propel) |
| Hooky v stuboch | `preSave` 56, `postSave` 14, `preDelete` 9, `postUpdate` 2, `postInsert` 1, `preUpdate` 1, `postDelete` 1 | `preInsert`, `postHydrate`, query hooky |
| Spojenie | `Perpl::getConnection` 253, `getReadConnection` 73, `getWriteConnection` 45, transakcie (69 `beginTransaction`), raw `prepare/query/exec` ~568 | replika/primary-replica, logger, debug mód, profiler, cache prepared statements |
| Metadáta | `getTableMap()` 173, `getValueSet` 105, `TYPE_FIELDNAME` 205, `TYPE_PHPNAME` 32, `TYPE_COLNAME` 20 | `TYPE_CAMELNAME`, `TYPE_NUM` (v app kóde), `translateFieldName` |
| CLI | `diff`, `migrate`, `build` (+ `sql:insert` v README) | ostatných 12 príkazov |

Dôležité zistenia:

- **`condition()` a `combine()` (351 volaní) sú v Perple „deprecated".** Idú cez `Criteria::__call` → `in_array` nad 38 menami → `trigger_deprecation` → `DeprecatedCriteriaMethods`. Zmeral som 3 volania error handlera na query a ~3× pomalšiu stavbu query (10,5 vs 3,5 µs). Vrstvu teda nemožno zmazať; tieto dve metódy treba naopak povýšiť na riadne API. **[M]**
- **Instance pooling:** 92× `disableInstancePooling` v 59 súboroch, 13× `enable`. `public/index.php:9` ho vypína až **po** `Application::run()`, teda bez účinku. **[M]**
- **Serializované modely v cache** (súborová `SimpleCache`): `User` (`Authentication.php:173`, TTL 10 s), `Client` (`WebClientAuth.php:93`, `Client.php:785`), kolekcie `OrderProduct` (`OrderCacheTrait.php`, TTL 2 h), typy dokladov (2 dni). Každá zmena tvaru properties sa ich týka. **[A]**
- **Dátumy:** gettery vracajú mutable `\DateTime` cez `PropelDateTime::newInstance`; `dateTimeClass` nie je nastavený.
- **`propel diff` generuje nesúvisiace SQL**, ktoré ručne mažete (asteel `CLAUDE.md:99`). Príčinu som neoveril. **[?]**

## 4. Čo sa dá zmazať

Riadky sú z `src/`, bez testov. **[A]**

| Čo | Riadky | Dopad na asteel | Poznámka |
|---|---|---|---|
| 15 nepoužitých behaviorov (ostáva Timestampable, Archivable, SyncedTable, Util) | ~12 000 | žiadny | Hľadajú sa podľa mena, každý adresár je samostatne zmazateľný. I18n a ConcreteInheritance odkazujú na Validate. |
| Platformy a reverse parsery pre pgsql, mssql, oracle, sqlsrv | ~5 500 | žiadny | Vetvy pre ne v generických triedach: `supportsSchemas`, sekvencie, `requiresTransactionInPostgres`, `CLOB_EMU`. |
| Runtime adaptéry mssql, oracle, pgsql, sqlsrv + `DebugPDO`, `PropelPDO`, `MSSQL/` | ~1 500 | žiadny | Zostanú `instanceof PgsqlAdapter` vetvy v 3 Like filtroch na vyčistenie. |
| 12 nepoužitých príkazov + manažéry (init, reverse, graphviz, datadictionary, config:*, sql:build, migration:up/down/status/create) | ~3 500 | žiadny | `sql:insert` je v README asteelu — overiť, či ho niekto púšťa. |
| Config loadery ini/json/yaml/xml/properties + `XmlToArrayConverter` | ~700 | žiadny | asteel používa PHP pole. |
| XSD/DTD/XSL + validačná a transformačná cesta | ~1 800 | žiadny | XSD validácia je defaultne vypnutá, XSL nemá volajúceho. |
| `Runtime/Validator` + behavior `validate` | ~800 | žiadny | Jediný dôvod pre `symfony/validator`. |
| `DeprecatedCriteriaMethods` okrem `condition`/`combine` | ~600 | **overiť grepom** zvyšných 36 mien | |
| Parser XML/YAML/CSV (JSON nechať alebo nahradiť `json_encode(toArray())`) | ~700 | 4 volania `exportTo('JSON')` | |
| Zastarané triedy: `ConnectionManagerMasterSlave`, `StatementFormatter`, `SimpleArrayFormatter` | ~300 | žiadny | |
| Root: `pom.xml.dist`, `autoload.php.dist`, `.license`, `UPDATE.md`, `.bat`, CircleCI skript | ~10 súborov | žiadny | `autoload.php.dist` ešte používa phpstan/psalm ako autoloader. |

**SQLite neodporúčam mazať:** 73 testovacích súborov stojí na `QuickBuilder` so `sqlite::memory:`. SQLite adaptér + platforma + parser je ~1 300 riadkov a drží celú testovaciu sadu.

## 5. Závislosti a PHP verzia

| Balík | Kto ho potrebuje | Návrh |
|---|---|---|
| `psr/log` | runtime (4 súbory) | nechať |
| `symfony/console` | CLI (23 súborov) | nechať |
| `symfony/config` | len `Common/Config` (6 súborov) | nahraditeľné poľom s defaultmi |
| `symfony/filesystem`, `symfony/finder` | CLI (7 + 5 súborov) | nahraditeľné `mkdir`/`glob` |
| `symfony/yaml` | YAML loader, `validate`, YamlParser | preč spolu s nimi |
| `symfony/validator` | len `Runtime/Validator` | preč |
| `symfony/translation` | **0 importov v `src/`** | preč hneď |
| `polyfill-php82/83/85` | nikto | preč hneď |
| `polyfill-php84` | `array_any` 9×, `array_find` 5×, `array_all` 1× | pri floor 8.4 preč; pri 8.3 nechať alebo prepísať 15 miest |

- **Floor 8.3** (ako si zadal) je bez problémov; ostane jeden polyfill.
- **Floor 8.4** dá navyše: `Pdo\Mysql` (kód ho už cez `class_exists` preferuje), `#[\Deprecated]`, lazy objects, property hooks, asymetrickú viditeľnosť a nula polyfillov. asteel aj hladamchatu lokálne bežia na 8.4; **produkčnú verziu PHP som neoveril.** **[?]**
- Lint celého `src/` pod PHP 8.5 je čistý (žiadne compile-time deprecations). **[M]**

## 6. Archaizmy — jazyk

Rector dry-run nad `src/` (bez šablón) **[M]**:

| Sada | Zmenených súborov | Najčastejšie pravidlá |
|---|---|---|
| PHP 8.3 | 144 | `??=` 37, constructor promotion 27, `::class` namiesto stringu 25, `str_contains` 23, first-class callable 23, `str_starts_with` 14, `Stringable` 13, `match` 9, `$x::class` 9 |
| PHP 8.4 navyše | +5 | `array_any` 4, `array_all` 1 |
| Type declarations | 78 | arrow fn return 30, typed property 27, `: static` 8 |
| Dead code | 76 | zbytočné `@param` 75, `@return` 74 |
| Code quality | 49 | explicitné bool porovnania 46, výmena type-checkov 24 |

Ručné počty **[M]**:

- **534 netypovaných properties** vs 245 typovaných — hlavný jazykový dlh. V runtime je ich 225, z toho 158 s jednoznačným `@var` (mechanicky prevoditeľné). **[A]**
- 715 z 4 730 funkcií bez návratového typu (59 v `Criteria`, 47 v `ModelCriteria`); ~107 metód s `@return $this` bez `: static`. **[A]**
- 0× `readonly`, 0 typovaných konštánt, 1 enum v celom kóde (`BuilderType`).
- 27× `#[\ReturnTypeWillChange]`; `Collection` stále `implements Serializable`; `PropelDateTime` má `__sleep/__wakeup`. **[A]**
- 88× `@deprecated`, 33× TODO/FIXME (napr. `MysqlAdapter.php:180` — hack kvôli PECL bugu z roku 2007).
- Rodiny stringových konštánt, ktoré sú de facto enumy: `Criteria` operátory (26), join typy, `TableMap::TYPE_*`, `RelationMap`, `PropelTypes` (70), FK akcie. **Pozor:** asteel má 2 066 použití `Criteria::*` — konštanty musia ostať, enum len popri nich.

## 7. Archaizmy — dizajn

- **Globálny statický stav:** `Perpl::$serviceContainer`, `Perpl::$isInstancePoolingEnabled`, `ConnectionWrapper::$useDebugMode`, `ConnectionFactory::$useProfilerConnection`, `InstancePoolTrait::$instances` (pool na každý TableMap). **[A]**
- **Veža dedenia query:** `Criteria` (2 535 riadkov, 122 public metód) → `BaseModelCriteria` → `ModelCriteria` (2 311) → generovaná Query (ProductQuery 599 public metód). **[M]**
- **Dve implementácie filtrov vedľa seba:** `ActiveQuery/Criterion/` (21 súborov, ~1 700 riadkov) aj `FilterExpression/` (19 súborov, ~2 470). **[A]**
- **Magické `__call` v 8 triedach**, najmä `ModelCriteria` (findByX, joinWithX…) a `Criteria` (deprecated vrstva). **[A]**
- **PDO:** charset default je `utf8` (= utf8mb3), nie `utf8mb4`; asteel má v configu tiež `utf8`. Kľúče volieb sa riešia cez `constant('PDO::' . $key)`, takže `MYSQL_ATTR_*` idú cez menný priestor, ktorý PHP 8.5 označuje za zastaraný. **[A]**
- **Dátumy:** `PropelDateTime extends DateTime` (mutable), `DateTimeImmutable` sa nepoužíva nikde.
- **MySQL platforma:**
  - `MysqlPlatform::getMajorServerVersionNumber()` má chybu: `substr($v, 0, $dotPos - 1)` vráti pre „8.0.x" aj „9.7.0" prázdny reťazec, teda verziu 0. Vetva pre MySQL 8+ (FK default `NO ACTION`) sa nikdy nevykoná. **[M — prečítané, nespustené]**
  - Predvolené šírky `int(11)`, `bigint(20)` sú spred MySQL 8.0.19.
  - Nepodporuje: generované stĺpce, CHECK, zlomkové sekundy, funkčné/zostupné indexy, `ALGORITHM=`/`LOCK=`.
  - Migrácie vykonáva vlastný znakový SQL splitter (`Util/SqlParser.php`).
- **Model generátora:** `Table` 2 410 riadkov / 126 public metód, `Column` 1 888 / 120, `ForeignKey` 1 288 / 71. **[A]**

## 8. Generovaný kód

Rozsah v asteel **[M]**: 1 252 súborov, 1 548 950 riadkov (~61 MB). Modely 851 tis., Query 462 tis., TableMap 236 tis. Komentáre tvoria 42 %. **[A]**

`Base/Product.php` (48 stĺpcov, 5 + 38 relácií):

- **~65 % súboru sú relácie**, asi 275 riadkov na jednu 1:n reláciu (init, get, set, count, add, remove, `getXJoinY`). **[A]**
- 79 metód `getXxxJoinYyy` je označených `@deprecated`; v celom generovanom kóde ich je ~1 300. asteel ich volá na 153 miestach (42× `getOrderProductsJoinProduct`). **[A]**
- `doInsert` 374 riadkov (reťaz `isColumnModified` + `switch` s `bindValue`), `toArray` 426, `hydrate` 217, `clearAllReferences` 247, `copyInto` 189.
- Gettery a settery stĺpcov nemajú natívne typy (`getId()`, `setId($v)`).

`Base/ProductQuery.php` (10 288 riadkov, 53 % komentáre):

- **8 metód na každú reláciu** (`filterByX`, `joinX`, `useXQuery`, `withXQuery`, `useXExistsQuery`, `useXNotExistsQuery`, `useInXQuery`, `useNotInXQuery`) = 344 metód. Z posledných piatich má asteel **4 volania** dokopy. **[A]**
- `@method` zoznam má 544 riadkov.

`Map/ProductTableMap.php`: 5 schém názvov polí, `$normalizedColumnNameMap` 340 riadkov, `buildRelations` 440 riadkov, deprecated `doInsert`/`doDelete`/`CLASS_DEFAULT` v každom z 301 mapov.

Cena načítania bez opcache **[M]**: `Product` 5,6 ms / 2,5 MB, `ProductQuery` 3,6 ms / 1,6 MB, `ProductTableMap` 1,1 ms / 0,3 MB.

Čo zoštíhliť (odhady, generátor som neupravoval a nič nepregeneroval):

| Zmena | Úspora | Mení API pre app? | Mení serializovaný tvar? |
|---|---|---|---|
| Vyhodiť docblocky duplikujúce natívne typy | ~25–35 % riadkov | nie | nie |
| Query: nechať 3 metódy na reláciu, zvyšných 5 riešiť genericky | ~100 riadkov / relácia | 4 miesta v asteel | nie |
| Vyhodiť `@method` zoznam | ~5 % Query súborov | nie (len IDE nápoveda) | nie |
| `getXJoinY`: generovať len používané, alebo jednu generickú metódu | ~32 000 riadkov | 153 miest | nie |
| `doInsert`/`toArray`/`fromArray`/`copyInto` riadené dátami z TableMap | ~1 000 riadkov na `Product` | nie | nie |
| `hydrate` len s numerickým indexom | 217 → ~60 riadkov | nie | nie |
| TableMap: vyhodiť alias mapu, deprecated metódy, nepoužité schémy názvov | ~300 riadkov / map | `getByName` s CAMELNAME by prestal fungovať | nie |
| Typované gettery/settery | — | **áno** (stuby s override, TypeError namiesto tichého castu) | nie |
| Relácie do jedného kontajnera namiesto 114 properties | −700 riadkov, menší objekt | stuby čítajúce `$this->collXxx` | **áno** |

## 9. Pamäť, GC, výkon

Namerané na asteel modeloch (PHP 8.4 CLI) **[M]**:

| Model | Properties | Prázdny objekt | `serialize()` prázdneho |
|---|---|---|---|
| `Product` | 192 | 4 135 B | 7 621 B |
| `User` | 263 | — | 11 101 B |
| `Client` | 159 | — | 5 878 B |

- `Product` má 192 properties vrátane stubu; samotný `Base/Product` 172, z toho 124 je réžia: 38× `collXxx`, 38× `collXxxPartial`, 38× `xxxScheduledForDeletion`, 5× `aXxx`. **[A]**
- `serialize(Product)` trvá 48 µs, `unserialize` 6,7 µs — rozdiel robí reflexia v `__sleep`.

Zistenia:

1. **`__sleep` mení živý objekt.** Generovaný `__sleep` (`Base/Product.php:1383`) najprv volá `clearAllReferences()` a až potom cez `ReflectionClass` vráti všetky properties. Dôsledok: `Client::postSave` (`Client.php:785`) a `Authentication.php:173` serializáciou zahodia načítané relácie z objektu, s ktorým request ďalej pracuje. Cache „JoinedProducts" v `OrderCacheTrait` podľa toho produkty neobsahuje. **[M pre kód, dôsledok v asteel neoverený behom]**
2. **Instance pool je neohraničený statický array** bez slabých referencií (`InstancePoolTrait.php:21`). Pri zapnutom poole `populateObject` vráti objekt z poolu **bez rehydratácie** — starý objekt vyhrá nad čerstvým riadkom (`TableMapBuilder.php:1271`). asteel to obchádza 92 vypnutiami. **[A]**
3. **`OnDemandIterator` vypína pooling globálne** v konštruktore a zapína až po dočítaní; `break` z cyklu ho nechá vypnutý do konca procesu (`OnDemandIterator.php:63,72`). asteel OnDemand nepoužíva. **[A]**
4. **Hydratácia robí zbytočnú prácu na každý riadok:** `serialize($pk)` v `ObjectFormatter.php:137` aj bez one-to-many joinu; pri pridaní do kolekcie `hashCode()` = `json_encode` + `crc32` + `spl_object_hash` a zápis do 3 polí (`ObjectCollection.php:488`). `crc32` ako kľúč znamená, že kolízia potichu pokazí `contains()`. **[M]**
5. **Každý `find()`, `count()`, `paginate()` hlboko klonuje celú query** (`keepQuery` je default true, `ModelCriteria.php:1299`). **[A]**
6. **Pager vždy spraví COUNT + SELECT** a drží query aj spojenie (`PropelModelPager.php:117`); asteel má 48 `paginate`. **[A]**
7. **Cykly referencií** rodič `collXxx` ↔ dieťa `aXxx` rieši len ručné `clearAllReferences()`; asteel ho volá 4×, `gc_collect_cycles` 1×. `__destruct` neexistuje nikde.
8. Dátumy sú už lazy (string + cache objektu), debug bookkeeping je defaultne vypnutý, cache prepared statements tiež — tu zisk nie je.

Kontext: asteel nemá žiadne dlhobežiace procesy (žiadny worker, daemon), všetko je request alebo jednorazový cron. Najťažšie exporty (`UniExport`, `Heureka`, `Arukereso`, `ProductPriceRegeneratorV2`) ORM obchádzajú cez raw PDO a Elasticsearch. Zmeny v ORM teda pomôžu hlavne bežným requestom, importerom a cache vrstve. 53 miest dvíha `memory_limit` (12× 2048M, 10× 4086–4096M). **[A]**

Návrhy:

| Zmena | Čo rozbije | Náročnosť |
|---|---|---|
| `__serialize`/`__unserialize` s pevným zoznamom stĺpcov, bez reflexie a bez mutácie | staré bloby — treba čítať aj starý tvar, alebo zmazať cache pri deployi | S–M |
| Pool vypnutý defaultne, zapínaný per query alebo v scope (`withPool(fn)`) | kód spoliehajúci na identitu; `ObjectCollection::populateRelation` | M |
| Pool cez `WeakReference` | nič podstatné | S–M |
| PK string namiesto `serialize($pk)` a `json_encode`+`crc32` | nič (odstráni riziko kolízie) | S |
| Preskočiť klon query pri jednorazových dotazoch | kód, ktorý query po `find()` znovu používa | S–M |
| `simplePaginate` bez COUNT, pager nedrží spojenie | nič (nové API) | S |
| Generátorové `iterate()`/`chunk($n)` s korektným scope poolu | nič (nové API) | M |
| Relácie v jednom lazy kontajneri | generované API, tvar blobov | L |

## 10. Stav testov (pred úpravami; aktuálny stav je v kapitole 14)

- Agnostická sada na PHP 8.4: 2 896 testov, **8 zlyhaní** **[M]**:
  - 3× `IncomingRelationCodeProducerCodeTest` — **spôsobené vlastným patchom** (`?bool collXxxPartial = null`); referenčné súbory testov neboli upravené.
  - 2× `InitCommandAgnosticTest` — chýba pgsql driver (prostredie).
  - 3× `IconvTransliterationTest` — iconv na macOS (prostredie).
- SQLite a MySQL sady som **nerozbehol** (fixtures padajú na pripojení; MySQL sada chce root prístup a vytvára databázy, do lokálnej DB som nesiahal).
- CI matica upstreamu: PHP 8.1 a 8.5 × sqlite/mysql/pgsql/agnostic × Symfony 6/7/8. PHPStan level 7 s baseline 55 chýb, Psalm level 5 s 81.
- asteel má ~28 PHPUnit súborov / ~208 testov, väčšina proti lokálnej DB — to je jediná záchranná sieť pre zmeny generovaného kódu.

## 11. Navrhované poradie

| Fáza | Obsah | Regenerácia modelov? | Riziko |
|---|---|---|---|
| **0. Základ** | Opraviť 3 vlastné zlyhávajúce testy. Rozbehnúť MySQL sadu lokálne. Floor PHP, vyhodiť polyfilly a `symfony/translation`. CI na 8.3/8.4 × mysql + agnostic. Zmazať zastarané root súbory. | nie | nízke |
| **1. Mazanie** | Tabuľka v kapitole 4, po jednom commite na položku. | nie | nízke — overiť testami a `propel build` nad asteel schémou (výstup má byť bajtovo zhodný) |
| **2. Mechanická modernizácia** | Rector sady z kapitoly 6, typy properties, `: static`, typované konštanty, `Serializable` preč. | nie | nízke až stredné — typy na metódach, ktoré prepisujú stuby v asteel |
| **3. API, na ktorom asteel stojí** | `condition`/`combine` ako riadne metódy; zvyšok deprecated vrstvy preč; rozhodnúť o `getXJoinY`. | nie | stredné |
| **4. Generovaný kód** | Kapitola 8 + `__serialize`. | **áno** + zmazať cache pri deployi | stredné až vysoké |
| **5. Pamäť a runtime dizajn** | Kapitola 9: pool, hash, klonovanie, pager, `iterate()`. Potom z asteel odstrániť 92 vypnutí poolu. | čiastočne | stredné |
| **6. Veľké prestavby (nenavrhujem teraz)** | Enumy namiesto konštánt, zlúčenie `Criterion`/`FilterExpression`, odstránenie statickej fasády, `DateTimeImmutable`. | áno | vysoké, zasahuje tisíce miest v asteel |

Samostatne, lebo to bolí už dnes: **zistiť, prečo `propel diff` generuje nesúvisiace SQL.** Kandidáti: chyba v detekcii verzie MySQL (FK default RESTRICT vs NO ACTION), šírky integerov, normalizácia defaultov. Treba pustiť `diff` proti reálnej DB a pozrieť, čo presne navrhuje. **[?]**

Z upstreamu 2.10.x stojí za prebratie jednotlivo: `count()` neklonuje vstupnú query (`5ee3410b6`), cache názvu collection triedy (`6e2abc3d8`), prípadne BIGINT ako `int` (3 stĺpce).

## 12. Čo som neoveril

- Produkčnú verziu PHP a MySQL.
- Či asteel volá niektoré z 36 zvyšných deprecated `Criteria` metód (grep len na `condition`/`combine`).
- Odhady úspor v generovanom kóde — generátor som neupravoval, nič som nepregeneroval.
- Dôsledky `__sleep` v behu asteel (odvodené z kódu).
- Príčinu šumu v `propel diff`.
- Čísla označené **[A]** sú z grepu a môžu byť o jednotky percent vedľa.

## 13. Čo s generovaným kódom — merania a návrh (doplnené 2026-10-12)

### Čo váži a čo sa používa **[M]**

Opcache (PHP 8.4, všetkých 916 načítateľných generovaných tried):

| Časť | Skriptov | Opcache |
|---|---|---|
| Base modely | 307 | 65,8 MB |
| Base query | 301 | 37,7 MB |
| TableMap | 308 | 18,1 MB |
| **Spolu** | 916 | **121,6 MB** (bez docblockov 94,9 MB) |

- Lokálne máš `opcache.memory_consumption=256`; generované modely z toho berú takmer polovicu. Produkčné nastavenie som neoveril — ak je tam default 128 MB, časť tried sa do cache nezmestí a kompiluje sa pri každom requeste. **[?]**
- Váha je sústredená v pár „hub" modeloch. Priemerný model má 24 properties (7 réžia relácií), ale `User` 254 (223 réžia) a 976 metód, `Product` 172 (119), `Client` 149 (102), `Order` 133 (96).

Ktoré vygenerované public metódy asteel vôbec volá (zhoda podľa mena kdekoľvek v 4 547 súboroch app kódu — teda horný odhad; 77 miest volá metódy dynamicky a tie sken nevidí):

| Skupina | Metód | Volaných | Riadkov |
|---|---|---|---|
| Model: gettery/settery stĺpcov a ostatné | 24 135 | 68 % | 495 tis. |
| Model: 1:n `getXs`/`setXs` | 934 | 30 % | 47 tis. |
| Model: 1:n `count/add/remove/init/clear/resetPartial` | 3 632 | 8 % | 73 tis. |
| Model: `getXsJoinY` (deprecated) | 969 | 5 % | 24 tis. |
| Query: `filterByX` | 4 931 | 54 % | 148 tis. |
| Query: `joinX` | 1 114 | 3 % * | 37 tis. |
| Query: `useXQuery` | 1 114 | 53 % | 21 tis. |
| Query: `withXQuery`, `Exists`, `In` varianty | 5 570 | **1 metóda** | 108 tis. |
| Query: ostatné | 2 724 | 45 % | 52 tis. |
| **Spolu** | 45 123 | 48 % | 1,0 mil. |

\* `joinX` sa volá hlavne nepriamo — z `useXQuery` a cez magické `leftJoinX`/`innerJoinX`/`joinWithX`. Tých 3 % je len priame volanie.

`User`: z 899 vlastných metód sa podľa mena volá 160. `UserQuery`: 45 zo 654.

### Návrh: tri kroky, od najlacnejšieho

**Krok 1 — diéta bez zmeny API.** Generovať to isté, len bez balastu.

- Vyhodiť `@param`/`@return`/`@throws`, ktoré opakujú natívny typ. `@method` riadky pre magické `findOneByX`/`orderByX` nechať, IDE ich potrebuje.
- Query: negenerovať `withXQuery`, `useXExistsQuery`, `useXNotExistsQuery`, `useInXQuery`, `useNotInXQuery`. Runtime už má generické `useExistsQuery('Relácia')` a asteel ho na jednom mieste aj používa. Dopad na asteel: 5 volaní (`NotificationApiController.php:2209,2220,2468,2479`, `OrderSaleLoader.php:293`).
- TableMap: vyhodiť deprecated `doInsert`/`doDelete`/`CLASS_DEFAULT`.
- Odhad: −110 tis. riadkov za query varianty a okolo −27 MB opcache za docblocky. Tvar objektov ani serializácia sa nemenia.

**Krok 2 — negenerovať, čo nikto nevolá.**

- `getXsJoinY`: generovať len na vyžiadanie (atribút na `<foreign-key>` alebo zoznam v configu). Volá sa 53 mien z 969.
- Opačná (1:n) strana relácie na modeli ako voliteľná. `User` má 74 prichádzajúcich relácií len preto, že `id_user` je všade; query strana (`useXQuery`) ostáva vždy.
- Toto mení API, ale kontrola `check-consumer.php` a sken použitia presne ukážu, čo by chýbalo.

**Krok 3 — tenké metódy, logika v runtime.**

- Vygenerovaná metóda ostane (typy, IDE), ale len deleguje: `getOrderProducts()` → `$this->relMany('OrderProduct', …)`. Z ~275 riadkov na reláciu ostane ~12.
- Stav relácií (`collX`, `collXPartial`, `xScheduledForDeletion`, `aX`) do jedného lazy kontajnera. `Product` klesne zo 4,1 kB na približne 1 kB na objekt, `User` ešte viac.
- Spolu s tým `__serialize`/`__unserialize` s pevným zoznamom stĺpcov (koniec reflexie a mazania relácií zo živého objektu), ktorý vie prečítať aj staré bloby.
- Rovnakým spôsobom `doInsert`, `hydrate`, `toArray`, `fromArray`, `copyInto` riadené dátami z TableMap.

Čo nenavrhujem: zrušiť generovanie a riešiť všetko cez `__call`/`__get`. Stratili by ste typy, doplňovanie v IDE aj statickú analýzu nad 913 `useXQuery` a tisíckami getterov.

### Ako to strážiť

`tests/consumer/check-consumer.php` vygeneruje modely asteelu týmto forkom, porovná ich s baseline, načíta všetkých 2 234 tried proti aktuálnemu runtime a porovná SQL pre 3 639 dotazov (SELECT + JOIN pre každú tabuľku a reláciu). Do asteelu ani do DB nezapisuje.

```bash
# raz, na známom dobrom stave
php8.4 tests/consumer/check-consumer.php /cesta/k/asteel.sk --save-baseline --load=app/modul/Custom/VendorItem
# po každej zmene ORM
php8.4 tests/consumer/check-consumer.php /cesta/k/asteel.sk --load=app/modul/Custom/VendorItem
```

Vedľajšie zistenia z neho o asteel.sk:

- V schéme je nový stĺpec `product.rating`, ale modely neboli pregenerované (`Base/Product.php`, `ProductQuery.php`, `ProductTableMap.php` sa líšia od čerstvého buildu). Inak fork reprodukuje 1 201 z 1 204 súborov bajt po bajte.
- 47 generovaných súborov patrí tabuľkám, ktoré už v schéme nie sú (`FileTag`, `FormText`, `MetaTagLayout`…). Sú v starom formáte, nedajú sa ani načítať (254 tried vrátane ich stubov padá na fatal) a sú teda mŕtvy kód.

## 14. Stav prác (2026-10-12, necommitnuté v pracovnom strome)

Hotové:

- **Testy opravené.** 3 referenčné súbory zosúladené s vlastným patchom `collXxxPartial`; `InitCommandAgnosticTest` už nemá natvrdo `/tmp` a preskočí sa bez `pdo_pgsql`; `IconvTransliterationTest` sa preskočí mimo glibc.
- **Nové testy:** `tests/Propel/Tests/ConsumerContract/` — 373 testov / 1 007 assertions nad API, ktoré asteel reálne používa (in-memory SQLite). `CurrentBehaviourTest` pripína správanie, ktoré sa má neskôr zámerne zmeniť (`__sleep`, BIGINT ako string, pool po `update()`).
- **Kontrola proti konzumentovi:** `tests/consumer/check-consumer.php` (kapitola 13).
- **Syntax na PHP 8.3:** `composer.json` `php >=8.3`, preč `polyfill-php82/83/85`; Rector sada PHP 8.3 cez `utils/rector/rector-php83.php` — 110 súborov, +384/−650 riadkov. CI matica a `phpcs.xml` prepnuté z 8.1 na 8.3.

Zámerne vynechané pravidlá Rectoru:

- reťazec → `::class`: mení úvodné `\` v hodnotách, ktoré idú do generovaného kódu a configu (rozbilo 2 testy);
- constructor promotion: presúva docblocky do signatúr a z kopírovaného `&$parameters` robí referenciu;
- návratový typ `never`: metódu potom nemožno prepísať metódou, ktorá vracia hodnotu.

Overené na PHP 8.3, 8.4 aj 8.5:

| Kontrola | Výsledok |
|---|---|
| Agnostická sada | 3 269 testov, 0 zlyhaní (13 preskočených, 4 incomplete) |
| SQLite sada | 1 337 testov, 0 zlyhaní (8.3 a 8.5) |
| asteel: generovaný kód | 1 204 / 1 204 súborov bajtovo zhodných s buildom z pôvodného 2.9.1 |
| asteel: načítanie tried | 2 234 tried, žiadne nové zlyhanie (254 padá už na pôvodnom kóde — mŕtve triedy) |
| asteel: SQL | 3 643 dotazov zhodných |
| phpcs | 1 chyba, existovala už predtým (`OgTableMapModifier.php:55`) |
| PHPStan | 8 chýb: 7 existovalo predtým, 1 pribudla prechodom vendoru na Symfony 7 (`Validator/Constraints/Length.php:61`) |

Neoverené:

- MySQL sada forku — setup skript chce root, dropne a vytvorí databázy `test`, `contest`, `migration`… a mení globálny `sql_mode`; bez súhlasu som ju nepúšťal.
- Vlastné testy asteelu — idú proti lokálnej DB.

## 15. Druhé kolo: generovaný kód, pamäť, rýchlosť (2026-10-12, necommitnuté)

Merané na kópii produkčných dát (`asteel_sk_perpl`), PHP 8.4, opcache zapnutá, Xdebug vypnutý, 5 000 riadkov na scenár, najlepší z 3 behov. Skript: `tests/consumer/bench-consumer.php`. **[M]**

Pozor: lokálne CLI má Xdebug v móde `profile`, čo časy zhruba zdvojnásobuje. Merania v kapitole 9 a porovnanie 2.9.1 vs 2.10.4 boli robené s ním — pomery platia, absolútne časy nie.

### Čo sa zmenilo

| Zmena | Kde |
|---|---|
| `__serialize`/`__unserialize` namiesto `__sleep` cez reflexiu. Neserializuje relácie ani cache dátumov, nemení živý objekt, číta aj starý formát. | `methodsTemplate.php`, `ObjectBuilder::resolveTransientProperties()` |
| Stav 1:n relácií (`collXPartial`, `xScheduledForDeletion`) v dvoch poliach na objekt namiesto dvoch properties na reláciu. | `OneToManyRelationCodeProducer.php`, `ObjectBuilder::addIncomingRelationStateAttributes()` |
| Index `ObjectCollection` sa stavia až pri prvom `contains()`/`search()`. | `Runtime/Collection/ObjectCollection.php` |
| Formatter: `TableMap` sa hľadá raz na dotaz, PK sa serializuje len pri one-to-many joine. | `Runtime/Formatter/ObjectFormatter.php`, `AbstractFormatter.php` |
| `condition()` a `combine()` sú riadne metódy `Criteria`. | `Runtime/ActiveQuery/Criteria.php` |
| Negenerujú sa `withXQuery`, `useXExistsQuery`, `useXNotExistsQuery`, `useInXQuery`, `useNotInXQuery`. | `QueryBuilder.php`, 3 šablóny zmazané |
| Z generovaných docblockov sa vyhadzujú `@param`/`@return`/`@var` zhodné s natívnym typom a `@throws`. | nový `Builder/Util/DocblockCompactor.php` |

### Výsledky

| | `Product` | `Order` | `OrderProduct` | `Client` |
|---|---|---|---|---|
| Hydratácia 5 000 objektov — čas | 25,5 → 21,2 ms (−17 %) | 16,4 → 13,8 (−16 %) | 20,0 → 18,0 (−10 %) | 20,2 → 17,4 (−14 %) |
| Hydratácia — pamäť | 24,1 → 13,4 MB (−44 %) | 14,4 → 9,8 (−32 %) | 9,2 → 8,3 (−10 %) | 17,3 → 10,3 (−41 %) |
| `serialize` 5 000 objektov | 269 → 10,9 ms (−96 %) | 96 → 7,6 (−92 %) | 47 → 6,9 (−85 %) | 103 → 8,2 (−92 %) |
| Veľkosť serializovaného objektu | 8 246 → 2 662 B | 6 066 → 1 895 | 2 712 → 1 552 | 6 284 → 1 729 |
| `unserialize` — čas | 44,6 → 54,4 ms (+22 %) | 35,9 → 40,9 (+14 %) | 21,1 → 17,6 (−17 %) | 38,8 → 30,9 (−20 %) |
| `unserialize` — pamäť | 124 → 13,3 MB (−89 %) | 114 → 9,7 (−92 %) | 70 → 8,0 (−89 %) | 117 → 10,2 (−91 %) |
| Stavba query s `condition()` | −4 % | −7 % | −8 % | −9 % |

- `unserialize` je pomalší tam, kde má stub veľa vlastných properties (`Product` ich má 20) — idú pomalšou cestou.
- Bez merateľnej zmeny: `findPk`, stavba query bez `condition()`, `count`/`exists`, `paginate`, gettery, `toArray`, ukladanie.
- Generovaný kód pre asteel: 1 505 tis. → 1 296 tis. riadkov (−13,9 %). Opcache pre generované triedy: 110,3 → 99,3 MB (−10 %), z toho query triedy 34,9 → 25,7 MB.

### Čo meranie ukázalo a nerieši to ORM

- **`paginate()`** stojí 7–69 ms na stranu (`Client` 7, `Product` 18, `Order` 28, `OrderProduct` 69), takmer celé je to `COUNT(*)` nad veľkou tabuľkou. Lenivý COUNT som nezaviedol: pager podľa počtu orezáva číslo strany ešte pred SELECTom, takže by sa zmenilo správanie pri strane za koncom.
- **Stavba query** stojí ~14–24 µs a profil je plochý (desiatky malých funkcií v `ColumnResolver`/`SqlBuilder`), bez jedného úzkeho miesta.
- **GC:** po one-to-many joine ostáva väčšina grafu v pamäti až do behu cyklického GC, ak rodičia majú deti (`Order`: 3,2 z 3,8 MB, `Client`: 4,9 z 5,0 MB na 1 000 rodičov), lebo dieťa drží referenciu na rodiča a naopak. Samotný zber trvá 0,6–1,1 ms na 1 000 rodičov. Odstránilo by to len prerobenie spätných referencií, čo som nerobil.

### Overenie

| Kontrola | Výsledok |
|---|---|
| Agnostická sada, PHP 8.3 / 8.4 / 8.5 | 3 278 testov, 0 zlyhaní |
| SQLite sada, PHP 8.3 / 8.4 / 8.5 | 1 332 testov, 0 zlyhaní |
| asteel: načítanie tried, PHP 8.3 / 8.4 / 8.5 | 2 234 tried, žiadne nové zlyhanie |
| asteel: SQL pre každú tabuľku a reláciu | 3 643 dotazov zhodných s 2.9.1 |
| asteel: reálne dáta, 300 modelov, 10 263 objektov | `toArray()` zhodné so 2.9.1 |
| Serializované modely medzi starým a novým ORM | 1 210 objektov, oba smery bez chyby |
| Staré (necommitnuté v asteel) generované triedy na novom runtime | načítajú sa, dáta zhodné (okrem `Product`, kde chýba `rating`) |
| phpcs / PHPStan | len chyby, ktoré existovali predtým |

Z 5 odstránených testov forku všetky testovali len zrušené vygenerované metódy. Tri contract testy, ktoré pripínali staré správanie serializácie, sú prepísané na nové.

Neoverené: MySQL sada forku a vlastné testy asteelu (kapitola 14).

Čo treba spraviť v asteel.sk, je v `ASTEEL-ZMENY.md`.

## 16. Tretie kolo: mazanie mŕtvej váhy (2026-10-12, necommitnuté)

Fork podporuje už len MySQL/MariaDB (a SQLite kvôli vlastným testom).

| Odstránené | Poznámka |
|---|---|
| PostgreSQL, Oracle, MSSQL, sqlsrv: adaptéry, platformy, reverse parsery a vetvy pre ne v generickom kóde | 14 súborov, ~4 500 riadkov |
| 15 behaviorov (ostali `timestampable`, `archivable` a ich `SyncedTable`, `Util`) a `Runtime/Validator` | ~12 200 riadkov; s nimi odišli `symfony/validator` a `symfony/translation` |
| Príkazy `init`, `database:reverse`, `graphviz:generate`, `datadictionary:export`, `config:preview` a ich manažéry | ~2 300 riadkov |
| Konfigurácia v YAML, JSON, INI a XML | fork číta už len `propel.php` / `perpl.php` (a `.dist`) |
| Export a import modelov do XML a CSV | ostal JSON a YAML |
| `resources/xsl`, `resources/dtd` a XSL transformácia schémy | XSD ostalo kvôli doplňovaniu v IDE |
| `PropelPDO`, `pom.xml.dist`, `.license`, `UPDATE.md`, `.bat` skripty, CircleCI skript | |

Ostali príkazy: `model:build`, `sql:build`, `sql:insert`, `config:convert`, `migration:diff|migrate|up|down|status|create`, `test:prepare`.

Veľkosť:

| | Pred | Po |
|---|---|---|
| `src/` | ~500 súborov, ~101 tis. riadkov | 378 súborov, 80,8 tis. riadkov (−20 %) |
| Testy forku | 376 súborov, 89 tis. riadkov | 320 súborov, 75,8 tis. riadkov |
| Závislosti | 8 balíkov + 4 polyfilly | `psr/log`, `symfony/yaml`, `config`, `console`, `filesystem`, `finder`, `polyfill-php84` |

Čo ostalo, hoci malo ísť preč:

- **`symfony/yaml` a `YamlParser`:** každý vygenerovaný model má `DEFAULT_STRING_FORMAT = 'YAML'`, takže `(string)$model` exportuje YAML. Zmena na JSON by zmenila výstup `__toString()` — to je rozhodnutie na aplikáciu.
- **`SimpleArrayFormatter`, `StatementFormatter`, `ConnectionManagerMasterSlave`, `DebugPDO`:** stále ich niečo používa (`select()` s jedným stĺpcom, `FORMAT_STATEMENT` v asteel, konverzia configu, testy).
- **Generické abstrakcie po cudzích DB** (sekvencie, `supportsSchemas`, `requiresTransactionInPostgres`, `CLOB_EMU`, `VendorInfo`): kód cez ne ešte ide aj pre MySQL/SQLite, odstránenie je samostatná práca.

Natívne typy som skúsil doplniť Rectorom a **vrátil späť**, dvakrát:

- Sada `typeDeclarations` odvodila návratový typ `static` pre `Behavior::getObjectBuilderModifier()`, hoci potomkovia vracajú iný objekt — fatal pri builde.
- Samotné typovanie private properties (106 properties v 23 súboroch) nechalo dve properties bez defaultu a kód ich čítal pred priradením (`ConfigurationManager::$config`, `BehaviorLocator::$composerDir`) — 477 padnutých testov. Čo by padlo v cestách, ktoré testy nepokrývajú, neviem, preto to treba robiť ručne po triedach.

`propel diff`: chyba v `MysqlPlatform::getMajorServerVersionNumber()` zostala. Výstup `diff` je s ňou zhodný so stavom pred úpravami a oprava by zmenila predvolené `ON DELETE` pri cudzích kľúčoch bez akcie, čo treba overiť aj na MariaDB.

### Overenie po treťom kole

| Kontrola | Výsledok |
|---|---|
| Agnostická sada, PHP 8.3 / 8.4 / 8.5 | 2 619 testov, 0 zlyhaní |
| SQLite sada, PHP 8.3 / 8.4 / 8.5 | 1 129 testov, 0 zlyhaní |
| asteel: generovaný kód | 1 204 / 1 204 súborov zhodných so stavom po druhom kole |
| asteel: načítanie tried a SQL | bez nového zlyhania, 3 643 dotazov zhodných |
| asteel: reálne dáta | 300 modelov, 10 263 objektov zhodných s 2.9.1; 1 210 serializovaných objektov sa prečíta |
| `propel diff` proti kópii DB | SQL migrácie zhodné s pôvodným 2.9.1 (50 príkazov) |
| phpcs | čisté |
| PHPStan | 2 chyby, obe existovali pred úpravami |

Počet testov klesol, lebo zmizli testy odstránených funkcií (z 3 278 na 2 619 agnostických, z 1 332 na 1 129 SQLite).

### MySQL sada a prevod modelu na reťazec (doplnené)

**MySQL sada forku** (lokálny MySQL 9.7, testovacie DB vytvorené a po behu zmazané; tvoja DB `test` ostala nedotknutá, použil som `DB_NAME=perpl_test`): **[M]**

| | Testov | Zlyhaní |
|---|---|---|
| Pôvodné 2.9.1 | 1 448 | 0 |
| Fork po úpravách | 1 241 | 0 |

- Pôvodná sada sa na tvojom stroji najprv vôbec nerozbehla: číselné heslo k DB sa z YAML šablóny fixtures načítalo ako `int` a spojenie padlo na `TypeError`. Vo forku to už neplatí, fixtures majú PHP konfiguráciu.
- Po vyradení PostgreSQL sa na MySQL začal púšťať test `BookstoreLoggingTest::testQueryJoins` (dovtedy bol označený aj ako `pgsql`, takže ho MySQL sada preskakovala) a odhalil starú chybu: join cez polymorfnú reláciu (`<reference local="…" value="…"/>`) padal na `getTableName() on null`. Opravené v `ModelJoin::setupJoinCondition()`. asteel polymorfné relácie nemá.

**`(string)$model` — YAML alebo JSON:**

- Formát určuje atribút `defaultStringFormat` na `<database>` alebo `<table>` v schéme; predvolený je `YAML`. Dá sa teda zmeniť v schéme asteelu bez zásahu do forku.
- Ovplyvňuje len `(string)$model` a `(string)$kolekcia`. `exportTo('JSON')`, `toArray()`, `serialize()` ani ukladanie sa nemenia.
- Na reálnom `Product`: YAML 62 µs a 1 022 B na objekt, JSON 3 µs a 1 081 B — asi 20× rýchlejšie. **[M]**
- V asteel som grepom nenašiel miesto, kde sa model alebo kolekcia prevádza na reťazec (žiadne `(string)$model`, `array_unique` beží nad poľami ID). Nepriamy prevod v šablónach alebo logoch grep spoľahlivo nenájde. **[A]**
- Po prepnutí všetkých schém na JSON by sa dal z forku vyhodiť `YamlParser` a závislosť `symfony/yaml`.

**Prechod na JSON je urobený** (po súhlase): predvolený formát je `JSON`, `YamlParser` a závislosť `symfony/yaml` sú preč. V generovanom kóde pre asteel sa zmenila len konštanta `DEFAULT_STRING_FORMAT` v 301 mapách a text troch docblockov. Agnostická sada má 2 571 testov, SQLite 1 129, MySQL 1 241, všetky bez zlyhania; reálne dáta z kópie DB sedia.

Závislosti forku teraz: `psr/log`, `symfony/config`, `symfony/console`, `symfony/filesystem`, `symfony/finder`, `symfony/polyfill-php84`.
