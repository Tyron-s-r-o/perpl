#!/usr/bin/env php
<?php

/**
 * Reads rows of every table of a consumer project through its models and writes
 * what the ORM returned to a file, or compares it with such a file written by
 * another state of the ORM. Also checks that serialized models can be exchanged
 * between the two states (caches of the consumer survive an ORM update and a rollback).
 *
 * Usage:
 *   php tests/consumer/data-consumer.php dump   <consumer-root> <file> --db=<datasource>:<database>... [options]
 *   php tests/consumer/data-consumer.php verify <consumer-root> <file> --db=<datasource>:<database>... [options]
 *
 * Options: --generated=<dir> --host=localhost --port=3306 --user=root --rows=50
 * The password is read from the environment variable DB_PASSWORD or MYSQL_PWD. Only reads from the database.
 */

declare(strict_types = 1);

use Propel\Runtime\Connection\ConnectionManagerSingle;
use Propel\Runtime\Map\TableMap;
use Propel\Runtime\Propel;

$mode = $argv[1] ?? '';
$consumerRoot = realpath($argv[2] ?? '') ?: null;
$file = $argv[3] ?? null;
$options = ['db' => [], 'generated' => null, 'host' => 'localhost', 'port' => '3306', 'user' => 'root', 'rows' => '50'];
foreach (array_slice($argv, 4) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) && array_key_exists($m[1], $options)) {
        if (is_array($options[$m[1]])) {
            $options[$m[1]][] = $m[2];
        } else {
            $options[$m[1]] = $m[2];
        }
    } else {
        fwrite(STDERR, "Unknown argument: $arg\n");
        exit(2);
    }
}
if (!in_array($mode, ['dump', 'verify'], true) || !$consumerRoot || !$file || !$options['db']) {
    fwrite(STDERR, "Usage: php tests/consumer/data-consumer.php dump|verify <consumer-root> <file> --db=<datasource>:<database>...\n");
    exit(2);
}

$ormRoot = dirname(__DIR__, 2);
ini_set('memory_limit', '-1');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '1');

$cwd = getcwd();
chdir($consumerRoot);
$config = is_file("$consumerRoot/perpl.php") ? include "$consumerRoot/perpl.php" : include "$consumerRoot/propel.php";
chdir((string)$cwd);
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
        $classFile = "$ormRoot/src/" . str_replace('\\', '/', $class) . '.php';
        if (is_file($classFile)) {
            require $classFile;
        }

        return;
    }
    if (!$freshDir) {
        return;
    }
    $classFile = $composer->findFile($class);
    $classFile = $classFile ? realpath($classFile) : false;
    if ($classFile && str_starts_with($classFile, (string)$phpDir) && preg_match('#/(Base|Map)/#', $classFile)) {
        $freshFile = $freshDir . substr($classFile, strlen((string)$phpDir));
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

// table map classes from the loader script
$loaderSource = (string)file_get_contents($loaderScript);
preg_match_all("/'tablesByName'\s*=>\s*array\s*\((.*?)\n\s*\),/s", $loaderSource, $blocks);
$tableMapClasses = [];
foreach ($blocks[1] as $block) {
    preg_match_all("/=> '([^']+TableMap)'/", $block, $classMatches);
    foreach ($classMatches[1] as $class) {
        $tableMapClasses[] = ltrim(str_replace('\\\\', '\\', $class), '\\');
    }
}
require $loaderScript;
$tableMapClasses = array_values(array_unique($tableMapClasses));
sort($tableMapClasses);

$rows = (int)$options['rows'];
$expected = $mode === 'verify' ? unserialize((string)file_get_contents($file)) : [];
$result = [];
$problems = [];
$objectCount = 0;
$blobCount = 0;

foreach ($tableMapClasses as $tableMapClass) {
    try {
        $modelClass = ltrim($tableMapClass::OM_CLASS, '\\');
        $queryClass = $modelClass . 'Query';
        $tableMap = $tableMapClass::getTableMap();
        $query = $queryClass::create()->limit($rows);
        foreach ($tableMap->getPrimaryKeys() as $pk) {
            $query->orderBy($pk->getPhpName(), 'desc');
        }
        $objects = $query->find()->getData();
        $entry = ['arrays' => [], 'blobs' => []];
        foreach ($objects as $index => $object) {
            $entry['arrays'][] = $object->toArray(TableMap::TYPE_FIELDNAME);
            if ($index < 5) {
                $entry['blobs'][] = serialize($object);
            }
        }
        $objectCount += count($objects);
        $result[$modelClass] = $entry;

        if ($mode === 'verify') {
            if (!isset($expected[$modelClass])) {
                $problems[] = "$modelClass: not in file";

                continue;
            }
            if ($expected[$modelClass]['arrays'] !== $entry['arrays']) {
                $problems[] = "$modelClass: toArray() of the hydrated rows differs";
            }
            foreach ($expected[$modelClass]['blobs'] as $index => $blob) {
                $blobCount++;
                $restored = unserialize($blob);
                if (!$restored instanceof $modelClass) {
                    $problems[] = "$modelClass: blob $index does not unserialize to the model";
                } elseif ($restored->toArray(TableMap::TYPE_FIELDNAME) !== $expected[$modelClass]['arrays'][$index]) {
                    $problems[] = "$modelClass: blob $index unserializes to different column values";
                } elseif ($restored->isNew() || $restored->isModified()) {
                    $problems[] = "$modelClass: blob $index unserializes as new or modified";
                }
            }
        }
        $tableMapClass::clearInstancePool();
    } catch (Throwable $e) {
        $problems[] = "$tableMapClass: " . get_class($e) . ': ' . substr($e->getMessage(), 0, 160);
    }
}

printf("ORM: %s (PHP %s)\n%d models, %d objects hydrated", $ormRoot, PHP_VERSION, count($result), $objectCount);
if ($mode === 'dump') {
    file_put_contents($file, serialize($result));
    printf(", written to %s\n", $file);
    foreach ($problems as $problem) {
        echo "  $problem\n";
    }

    exit(0);
}
printf(", %d serialized objects of the other ORM state read, %d problems\n", $blobCount, count($problems));
foreach (array_slice($problems, 0, 30) as $problem) {
    echo "  $problem\n";
}
exit($problems ? 1 : 0);
