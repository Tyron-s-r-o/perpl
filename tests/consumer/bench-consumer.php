#!/usr/bin/env php
<?php

/**
 * Benchmarks this working copy of the ORM with the models of a consumer project
 * against a database of that project. Meant to be run for two states of the ORM
 * with the same arguments; compare the JSON results with --compare.
 *
 * Usage:
 *   php tests/consumer/bench-consumer.php <consumer-root> --db=<datasource>:<database>... --model=<ModelClass>...
 *       [--generated=<dir>] [--host=localhost] [--port=3306] [--user=root] [--rows=5000] [--repeat=3]
 *       [--writes] [--json=<file>] [--only=<scenario,...>]
 *   php tests/consumer/bench-consumer.php --compare <before.json> <after.json>
 *
 * --generated   directory with php/ and conf/loadDatabase.php as written by check-consumer.php
 *               (its work dir or baseline dir); defaults to the classes checked in by the consumer
 * --writes      also run scenarios that write (inside a transaction that is rolled back)
 *
 * The password is read from the environment variable DB_PASSWORD or MYSQL_PWD.
 */

declare(strict_types = 1);

use Propel\Runtime\Connection\ConnectionManagerSingle;
use Propel\Runtime\Map\RelationMap;
use Propel\Runtime\Propel;

if (($argv[1] ?? '') === '--compare') {
    exit(compare($argv[2], $argv[3]));
}

$options = ['db' => [], 'model' => [], 'generated' => null, 'host' => 'localhost', 'port' => '3306', 'user' => 'root', 'rows' => '5000', 'repeat' => '3', 'json' => null, 'only' => null];
$writes = false;
$consumerRoot = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--writes') {
        $writes = true;
    } elseif (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) && array_key_exists($m[1], $options)) {
        if (is_array($options[$m[1]])) {
            $options[$m[1]][] = $m[2];
        } else {
            $options[$m[1]] = $m[2];
        }
    } elseif ($consumerRoot === null && !str_starts_with($arg, '--')) {
        $consumerRoot = realpath($arg) ?: null;
    } else {
        fwrite(STDERR, "Unknown argument: $arg\n");
        exit(2);
    }
}
if (!$consumerRoot || !$options['db'] || !$options['model']) {
    fwrite(STDERR, "Usage: php tests/consumer/bench-consumer.php <consumer-root> --db=<datasource>:<database>... --model=<ModelClass>...\n");
    exit(2);
}

$ormRoot = dirname(__DIR__, 2);
ini_set('memory_limit', '-1');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '1');

$config = (function () use ($consumerRoot) {
    foreach (['perpl.php', 'propel.php'] as $name) {
        if (is_file("$consumerRoot/$name")) {
            $cwd = getcwd();
            chdir($consumerRoot);
            $config = include "$consumerRoot/$name";
            chdir((string)$cwd);

            return is_array($config) ? $config : [];
        }
    }

    return [];
})();
$paths = $config['propel']['paths'] ?? [];
$phpDir = realpath($consumerRoot . '/' . ($paths['phpDir'] ?? 'generated-classes'));
$loaderScript = $consumerRoot . '/' . ($paths['phpConfDir'] ?? 'generated-conf') . '/loadDatabase.php';
$freshDir = null;
if ($options['generated']) {
    $freshDir = realpath($options['generated'] . '/php');
    $loaderScript = $options['generated'] . '/conf/loadDatabase.php';
}

/** @var \Composer\Autoload\ClassLoader $composer */
$composer = require "$consumerRoot/vendor/autoload.php";
spl_autoload_register(function (string $class) use ($ormRoot, $composer, $phpDir, $freshDir): void {
    if (str_starts_with($class, 'Propel\\')) {
        $file = "$ormRoot/src/" . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require $file;
        }

        return;
    }
    if (!$freshDir) {
        return;
    }
    $file = $composer->findFile($class);
    $file = $file ? realpath($file) : false;
    if ($file && str_starts_with($file, (string)$phpDir) && preg_match('#/(Base|Map)/#', $file)) {
        $freshFile = $freshDir . substr($file, strlen((string)$phpDir));
        if (is_file($freshFile)) {
            require $freshFile;
        }
    }
}, true, true);

$serviceContainer = Propel::getServiceContainer();
$password = getenv('DB_PASSWORD') ?: (getenv('MYSQL_PWD') ?: '');
foreach ($options['db'] as $i => $mapping) {
    [$datasource, $database] = explode(':', $mapping, 2);
    $serviceContainer->setAdapterClass($datasource, 'mysql');
    $manager = new ConnectionManagerSingle($datasource);
    $manager->setConfiguration([
        'dsn' => "mysql:host={$options['host']};port={$options['port']};dbname=$database",
        'user' => $options['user'],
        'password' => $password,
        'settings' => ['charset' => 'utf8', 'queries' => []],
        'classname' => '\\Propel\\Runtime\\Connection\\ConnectionWrapper',
    ]);
    $serviceContainer->setConnectionManager($manager);
    if ($i === 0) {
        $serviceContainer->setDefaultDatasource($datasource);
    }
}
require $loaderScript;

$rows = (int)$options['rows'];
$repeat = max(1, (int)$options['repeat']);
$only = $options['only'] ? explode(',', $options['only']) : null;
$results = ['meta' => ['orm' => $ormRoot, 'php' => PHP_VERSION, 'rows' => $rows, 'generated' => $freshDir ?: 'consumer'], 'models' => []];
printf("ORM: %s\nPHP: %s, rows per scenario: %d, repeat: %d, generated classes: %s\n", $ormRoot, PHP_VERSION, $rows, $repeat, $freshDir ?: 'consumer');

foreach ($options['model'] as $modelClass) {
    $modelClass = ltrim($modelClass, '\\');
    $results['models'][$modelClass] = benchModel($modelClass, $rows, $repeat, $writes, $only);
}

if ($options['json']) {
    file_put_contents($options['json'], json_encode($results, JSON_PRETTY_PRINT));
    echo "\nResults written to {$options['json']}\n";
}
exit(0);

/**
 * @param string $modelClass
 * @param int $rows
 * @param int $repeat
 * @param bool $writes
 * @param array<string>|null $only
 *
 * @return array<string, array<string, float|int>>
 */
function benchModel(string $modelClass, int $rows, int $repeat, bool $writes, ?array $only): array
{
    $queryClass = $modelClass . 'Query';
    $tableMapClass = $modelClass::TABLE_MAP;
    $tableMap = $tableMapClass::getTableMap();
    $con = Propel::getConnection($tableMapClass::DATABASE_NAME);
    $table = $tableMap->getName();
    $pkColumns = $tableMap->getPrimaryKeys();
    $pk = reset($pkColumns);
    $pkName = $pk->getName();
    $pkPhpName = $pk->getPhpName();

    $columns = array_values($tableMap->getColumns());
    $getters = array_map(fn ($column) => 'get' . $column->getPhpName(), $columns);
    // first many-to-one relation and the one-to-many relation with the most rows, ignoring self references
    $tableRows = $con->query('SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);
    $manyToOne = null;
    $oneToMany = null;
    $oneToManyRows = -1;
    foreach ($tableMap->getRelations() as $relation) {
        $otherTable = $relation->getType() === RelationMap::MANY_TO_ONE ? $relation->getForeignTable() : $relation->getLocalTable();
        if ($otherTable->getName() === $table) {
            continue;
        }
        if ($relation->getType() === RelationMap::MANY_TO_ONE && !$manyToOne) {
            $manyToOne = $relation;
        }
        $otherRows = (int)($tableRows[$otherTable->getName()] ?? 0);
        if ($relation->getType() === RelationMap::ONE_TO_MANY && $otherRows > $oneToManyRows) {
            $oneToMany = $relation;
            $oneToManyRows = $otherRows;
        }
    }
    $stringColumn = null;
    foreach ($columns as $column) {
        if (!$column->isPrimaryKey() && !$column->isForeignKey() && $column->getType() === 'VARCHAR') {
            $stringColumn = $column;

            break;
        }
    }

    $ids = $con->query("SELECT `$pkName` FROM `$table` ORDER BY `$pkName` DESC LIMIT " . max($rows, 2000))->fetchAll(PDO::FETCH_COLUMN);
    $totalRows = (int)$con->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    printf(
        "\n== %s (table %s, %d rows, %d columns, %d relations; many-to-one: %s, one-to-many: %s)\n",
        $modelClass,
        $table,
        $totalRows,
        count($columns),
        count($tableMap->getRelations()),
        $manyToOne ? $manyToOne->getName() : '-',
        $oneToMany ? $oneToMany->getName() : '-',
    );
    printf("   %-28s %10s %12s %10s  %s\n", 'scenario', 'ms', 'ms/1000', 'mem MB', 'note');

    $clearPools = function () use ($tableMap): void {
        $tableMapClass = get_class($tableMap);
        $tableMapClass::clearInstancePool();
        foreach ($tableMap->getRelations() as $relation) {
            $relatedMapClass = get_class($relation->getForeignTable()) === $tableMapClass ? get_class($relation->getLocalTable()) : get_class($relation->getForeignTable());
            $relatedMapClass::clearInstancePool();
        }
        gc_collect_cycles();
    };
    $orderMethod = 'orderBy' . $pkPhpName;
    $filterPk = 'filterBy' . $pkPhpName;
    $newQuery = fn () => $queryClass::create()->$orderMethod('desc')->limit($rows);

    $scenarios = [];
    $scenarios['raw_pdo_fetch_all'] = function () use ($con, $table, $pkName, $rows) {
        $data = $con->query("SELECT * FROM `$table` ORDER BY `$pkName` DESC LIMIT $rows")->fetchAll(PDO::FETCH_NUM);

        return [count($data), $data];
    };
    $scenarios['hydrate'] = function () use ($newQuery) {
        $collection = $newQuery()->find();

        return [count($collection), $collection];
    };
    $scenarios['hydrate_no_pool'] = function () use ($newQuery) {
        Propel::disableInstancePooling();
        try {
            $collection = $newQuery()->find();
        } finally {
            Propel::enableInstancePooling();
        }

        return [count($collection), $collection];
    };
    $scenarios['select_3_columns'] = function () use ($newQuery, $columns) {
        $names = array_map(fn ($column) => $column->getPhpName(), array_slice($columns, 0, 3));
        $data = $newQuery()->select($names)->find()->getData();

        return [count($data), $data];
    };
    if ($manyToOne) {
        $joinWith = 'leftJoinWith' . $manyToOne->getName();
        $scenarios['join_with_many_to_one'] = function () use ($newQuery, $joinWith) {
            $collection = $newQuery()->$joinWith()->find();

            return [count($collection), $collection];
        };
    }
    $scenarios['find_pk_queries'] = function () use ($queryClass, $ids, $clearPools) {
        $n = 0;
        Propel::disableInstancePooling();
        try {
            foreach (array_slice($ids, 0, 2000) as $id) {
                $n += $queryClass::create()->findPk($id) ? 1 : 0;
            }
        } finally {
            Propel::enableInstancePooling();
        }

        return [$n, null];
    };
    $scenarios['query_build_only'] = function () use ($queryClass, $filterPk, $orderMethod, $stringColumn, $manyToOne) {
        $n = 3000;
        for ($i = 0; $i < $n; $i++) {
            $query = $queryClass::create()->$filterPk([1, 2, 3])->$orderMethod()->limit(10);
            if ($stringColumn) {
                $query->{'filterBy' . $stringColumn->getPhpName()}('abc%', 'LIKE');
            }
            if ($manyToOne) {
                $query = $query->{'use' . $manyToOne->getName() . 'Query'}()->limit(1)->endUse();
            }
            $params = [];
            $query->createSelectSql($params);
        }

        return [$n, null];
    };
    $scenarios['query_build_conditions'] = function () use ($queryClass, $modelClass, $pkPhpName) {
        $n = 3000;
        $short = substr((string)strrchr('\\' . $modelClass, '\\'), 1);
        for ($i = 0; $i < $n; $i++) {
            $query = $queryClass::create()
                ->condition('c1', "$short.$pkPhpName > ?", 5)
                ->condition('c2', "$short.$pkPhpName < ?", 500)
                ->where(['c1', 'c2'], 'or');
            $params = [];
            $query->createSelectSql($params);
        }

        return [$n, null];
    };
    $scenarios['count_and_exists'] = function () use ($queryClass, $filterPk, $ids) {
        $n = 0;
        foreach (array_slice($ids, 0, 300) as $id) {
            $n += $queryClass::create()->$filterPk($id)->count();
            $n += $queryClass::create()->$filterPk($id)->exists() ? 1 : 0;
        }

        return [600, null];
    };
    $scenarios['paginate'] = function () use ($queryClass, $orderMethod) {
        $n = 0;
        for ($page = 1; $page <= 20; $page++) {
            $pager = $queryClass::create()->$orderMethod('desc')->paginate($page, 50);
            $n += count($pager->getResults());
        }

        return [$n, null];
    };

    $measured = [];
    $hydrated = null;
    foreach ($scenarios as $name => $scenario) {
        if ($only && !in_array($name, $only, true)) {
            continue;
        }
        $measured[$name] = runScenario($name, $scenario, $repeat, $clearPools, $kept);
        if ($name === 'hydrate') {
            $hydrated = $kept;
        }
    }

    // scenarios on already hydrated objects
    if ($hydrated === null) {
        $clearPools();
        $hydrated = $newQuery()->find();
    }
    $objects = $hydrated->getData();
    $onObjects = [];
    $onObjects['getters_all_columns'] = function () use ($objects, $getters) {
        $n = 0;
        foreach ($objects as $object) {
            foreach ($getters as $getter) {
                $object->$getter();
                $n++;
            }
        }

        return [count($objects), null];
    };
    $onObjects['to_array'] = function () use ($objects) {
        foreach ($objects as $object) {
            $object->toArray();
        }

        return [count($objects), null];
    };
    $serialized = [];
    $onObjects['serialize'] = function () use ($objects, &$serialized) {
        $serialized = [];
        foreach ($objects as $object) {
            $serialized[] = serialize($object);
        }

        return [count($objects), null, 'avg bytes ' . (int)(array_sum(array_map('strlen', $serialized)) / max(1, count($serialized)))];
    };
    $onObjects['unserialize'] = function () use (&$serialized) {
        $restored = [];
        foreach ($serialized as $blob) {
            $restored[] = unserialize($blob);
        }

        return [count($restored), $restored];
    };
    if ($oneToMany) {
        $relationGetter = 'get' . $oneToMany->getPluralName();
        $onObjects['lazy_one_to_many'] = function () use ($objects, $relationGetter) {
            $children = 0;
            $subset = array_slice($objects, 0, 300);
            foreach ($subset as $object) {
                $children += count($object->$relationGetter());
            }
            foreach ($subset as $object) {
                $object->clearAllReferences(true);
            }

            return [count($subset), null, "$children children"];
        };
    }
    if ($writes && $stringColumn) {
        $setter = 'set' . $stringColumn->getPhpName();
        $getter = 'get' . $stringColumn->getPhpName();
        $onObjects['update_rollback'] = function () use ($objects, $setter, $getter, $con) {
            $subset = array_slice($objects, 0, 300);
            $con->beginTransaction();
            try {
                foreach ($subset as $object) {
                    $original = $object->$getter();
                    $object->$setter(substr($original . 'x', 0, 20));
                    $object->save($con);
                }
            } finally {
                $con->rollBack();
            }

            return [count($subset), null];
        };
    }
    $noClear = function (): void {
    };
    foreach ($onObjects as $name => $scenario) {
        if ($only && !in_array($name, $only, true)) {
            continue;
        }
        $measured[$name] = runScenario($name, $scenario, $name === 'update_rollback' ? 1 : $repeat, $noClear, $kept);
    }

    // memory held by cycles: joined collection dropped without clearAllReferences
    if ($oneToMany && (!$only || in_array('gc_after_one_to_many', $only, true))) {
        unset($hydrated, $objects, $kept, $serialized);
        $clearPools();
        Propel::disableInstancePooling();
        $joinWith = 'leftJoinWith' . $oneToMany->getName();
        gc_collect_cycles();
        $gcBefore = gc_status();
        $memoryBefore = memory_get_usage();
        $start = hrtime(true);
        $collection = $queryClass::create()->$filterPk(array_slice($ids, 0, min(1000, $rows)))->$joinWith()->find();
        $elapsed = (hrtime(true) - $start) / 1e6;
        $count = count($collection);
        $memoryHeld = memory_get_usage() - $memoryBefore;
        unset($collection);
        $memoryAfterUnset = memory_get_usage() - $memoryBefore;
        $startGc = hrtime(true);
        $collected = gc_collect_cycles();
        $gcMs = (hrtime(true) - $startGc) / 1e6;
        $memoryAfterGc = memory_get_usage() - $memoryBefore;
        $gcAfter = gc_status();
        Propel::enableInstancePooling();
        $measured['gc_after_one_to_many'] = [
            'ms' => $elapsed,
            'items' => $count,
            'mem_mb' => $memoryHeld / 1048576,
            'mem_after_unset_mb' => $memoryAfterUnset / 1048576,
            'mem_after_gc_mb' => $memoryAfterGc / 1048576,
            'gc_ms' => $gcMs,
            'gc_collected' => $collected,
            'gc_runs_during' => $gcAfter['runs'] - $gcBefore['runs'] - 1,
        ];
        printf(
            "   %-28s %10.1f %12.2f %10.2f  held after unset %.2f MB, after gc %.2f MB, gc %.1f ms (%d cycles), %d auto gc runs\n",
            'gc_after_one_to_many',
            $elapsed,
            $count ? $elapsed * 1000 / $count : 0,
            $memoryHeld / 1048576,
            $memoryAfterUnset / 1048576,
            $memoryAfterGc / 1048576,
            $gcMs,
            $collected,
            $gcAfter['runs'] - $gcBefore['runs'] - 1,
        );
    }

    return $measured;
}

/**
 * @param string $name
 * @param callable $scenario Returns [item count, value to keep while memory is measured, optional note]
 * @param int $repeat
 * @param callable $reset
 * @param mixed $kept
 *
 * @return array<string, float|int|string>
 */
function runScenario(string $name, callable $scenario, int $repeat, callable $reset, &$kept): array
{
    $best = null;
    $memory = 0;
    $items = 0;
    $note = '';
    $result = null;
    for ($i = 0; $i < $repeat; $i++) {
        $kept = null;
        $result = null;
        $reset();
        $memoryBefore = memory_get_usage();
        $start = hrtime(true);
        try {
            $result = $scenario();
        } catch (Throwable $e) {
            printf("   %-28s FAILED: %s: %s\n", $name, get_class($e), substr($e->getMessage(), 0, 160));

            return ['error' => get_class($e) . ': ' . $e->getMessage()];
        }
        $elapsed = (hrtime(true) - $start) / 1e6;
        [$items, $kept] = $result;
        $note = $result[2] ?? '';
        $memory = memory_get_usage() - $memoryBefore;
        $best = $best === null ? $elapsed : min($best, $elapsed);
    }
    printf("   %-28s %10.1f %12.2f %10.2f  %s\n", $name, $best, $items ? $best * 1000 / $items : 0, $memory / 1048576, trim("$items items $note"));

    return ['ms' => $best, 'items' => $items, 'mem_mb' => $memory / 1048576, 'note' => $note];
}

/**
 * @param string $beforeFile
 * @param string $afterFile
 *
 * @return int
 */
function compare(string $beforeFile, string $afterFile): int
{
    $before = json_decode((string)file_get_contents($beforeFile), true);
    $after = json_decode((string)file_get_contents($afterFile), true);
    printf("before: %s (PHP %s)\nafter:  %s (PHP %s)\n", $before['meta']['orm'], $before['meta']['php'], $after['meta']['orm'], $after['meta']['php']);
    foreach ($before['models'] as $model => $scenarios) {
        printf("\n== %s\n   %-28s %10s %10s %8s   %9s %9s %8s  %s\n", $model, 'scenario', 'ms before', 'ms after', 'change', 'MB before', 'MB after', 'change', 'note after');
        foreach ($scenarios as $name => $b) {
            $a = $after['models'][$model][$name] ?? null;
            if (!$a || isset($b['error']) || isset($a['error'])) {
                printf("   %-28s %s\n", $name, $a['error'] ?? ($b['error'] ?? 'missing in after'));

                continue;
            }
            $change = fn ($x, $y) => abs($x) < 1e-9 ? '' : sprintf('%+.0f%%', 100 * ($y - $x) / $x);
            printf(
                "   %-28s %10.1f %10.1f %8s   %9.2f %9.2f %8s  %s\n",
                $name,
                $b['ms'],
                $a['ms'],
                $change($b['ms'], $a['ms']),
                $b['mem_mb'],
                $a['mem_mb'],
                abs($b['mem_mb']) < 0.05 ? '' : $change($b['mem_mb'], $a['mem_mb']),
                $a['note'] ?? (isset($a['mem_after_gc_mb']) ? sprintf('after unset %.2f MB, gc %.1f ms', $a['mem_after_unset_mb'], $a['gc_ms']) : ''),
            );
        }
    }

    return 0;
}
