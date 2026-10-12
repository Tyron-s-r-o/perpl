#!/usr/bin/env php
<?php

/**
 * Checks this working copy of the ORM against a consumer project, without
 * writing into the consumer and without a database connection.
 *
 * 1. build     - generates the consumer's models with this generator into a work dir
 * 2. diff      - compares generated Base/ and Map/ classes with the ones checked in by the consumer
 * 3. load      - loads every generated class and every consumer class in the model dirs against
 *                this runtime (catches incompatible signatures and removed parents)
 * 4. sql       - builds SELECT and JOIN statements for every table and relation
 *
 * Results are compared with a baseline: run once with --save-baseline on a known good state
 * of the ORM, then run without it after changing the ORM. Without a baseline, the generated
 * classes are compared with the ones checked in by the consumer and every load error fails.
 *
 * Usage:
 *   php tests/consumer/check-consumer.php <consumer-root> [--save-baseline] [--load=<path>]...
 *       [--work-dir=<dir>] [--baseline=<dir>] [--generated=fresh|consumer] [--max-diffs=<n>]
 *
 * --load        additional file or directory (relative to consumer root) with classes to load
 * --baseline    baseline directory, defaults to <work-dir>/baseline
 * --generated   load generated classes from the fresh build (default) or as checked in by the consumer
 *
 * Exit code is 0 if nothing changed against the baseline.
 */

declare(strict_types = 1);

const GENERATED_DIR_PATTERN = '#/(Base|Map)/#';

if (($argv[1] ?? '') === '--child') {
    exit(runChild($argv[2]));
}

exit(main($argv));

/**
 * @param array<string> $argv
 *
 * @return int
 */
function main(array $argv): int
{
    $options = ['load' => [], 'work-dir' => null, 'baseline' => null, 'generated' => 'fresh', 'max-diffs' => '5'];
    $consumerRoot = null;
    $saveBaseline = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--save-baseline') {
            $saveBaseline = true;
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

            return 2;
        }
    }
    if (!$consumerRoot || !is_file("$consumerRoot/vendor/autoload.php")) {
        fwrite(STDERR, "Usage: php tests/consumer/check-consumer.php <consumer-root> [--load=<path>]...\n");

        return 2;
    }

    $ormRoot = dirname(__DIR__, 2);
    $config = loadConsumerConfig($consumerRoot);
    $paths = $config['propel']['paths'] ?? [];
    $schemaDir = absolutePath($consumerRoot, $paths['schemaDir'] ?? 'schema');
    $phpDir = absolutePath($consumerRoot, $paths['phpDir'] ?? 'generated-classes');
    $confDir = absolutePath($consumerRoot, $paths['phpConfDir'] ?? 'generated-conf');
    $workDir = $options['work-dir'] ?: sys_get_temp_dir() . '/orm-consumer-check-' . substr(md5($consumerRoot . $ormRoot), 0, 8);
    $baselineDir = $options['baseline'] ?: "$workDir/baseline";
    $hasBaseline = !$saveBaseline && is_dir("$baselineDir/php");
    $compareDir = $hasBaseline ? "$baselineDir/php" : $phpDir;
    $compareLabel = $hasBaseline ? 'baseline' : 'consumer';

    removeDir("$workDir/php");
    removeDir("$workDir/conf");
    @mkdir("$workDir/php", 0777, true);
    @mkdir("$workDir/conf", 0777, true);

    printf("ORM:      %s\nConsumer: %s\nWork dir: %s\nPHP:      %s\n", $ormRoot, $consumerRoot, $workDir, PHP_VERSION);
    echo 'Baseline: ' . ($saveBaseline ? "saving to $baselineDir" : ($hasBaseline ? $baselineDir : 'none, comparing with classes checked in by the consumer')) . "\n\n";
    $failed = [];

    // 1. build
    $command = sprintf(
        '%s -d memory_limit=-1 %s model:build --config-dir=%s --schema-dir=%s --output-dir=%s --loader-script-dir=%s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg("$ormRoot/bin/propel"),
        escapeshellarg($consumerRoot),
        escapeshellarg($schemaDir),
        escapeshellarg("$workDir/php"),
        escapeshellarg("$workDir/conf"),
    );
    $start = microtime(true);
    $output = [];
    $exitCode = 0;
    $cwd = getcwd();
    chdir($consumerRoot);
    exec($command, $output, $exitCode);
    chdir((string)$cwd);
    $generatedFiles = array_values(array_filter(listPhpFiles("$workDir/php"), fn (string $f) => (bool)preg_match(GENERATED_DIR_PATTERN, $f)));
    printf("[build] exit %d, %d generated Base/Map files, %.1fs\n", $exitCode, count($generatedFiles), microtime(true) - $start);
    if ($exitCode !== 0 || !$generatedFiles) {
        echo implode("\n", array_slice($output, -15)) . "\n";

        return 1;
    }

    // 2. diff
    $different = [];
    $missingInConsumer = [];
    foreach ($generatedFiles as $file) {
        $relative = substr($file, strlen("$workDir/php"));
        if (!is_file($compareDir . $relative)) {
            $missingInConsumer[] = $relative;
        } elseif (md5_file($file) !== md5_file($compareDir . $relative)) {
            $different[] = $relative;
        }
    }
    $generatedRelative = array_flip(array_map(fn (string $f) => substr($f, strlen("$workDir/php")), $generatedFiles));
    $staleInConsumer = [];
    foreach (listPhpFiles($compareDir) as $file) {
        $relative = substr($file, strlen($compareDir));
        if (preg_match(GENERATED_DIR_PATTERN, $relative) && !isset($generatedRelative[$relative])) {
            $staleInConsumer[] = $relative;
        }
    }
    $compareLoader = $hasBaseline ? "$baselineDir/conf/loadDatabase.php" : "$confDir/loadDatabase.php";
    $loaderDiffers = is_file($compareLoader) && md5_file($compareLoader) !== md5_file("$workDir/conf/loadDatabase.php");
    printf(
        "[diff]  against $compareLabel: %d identical, %d different, %d new, %d gone, loader script %s\n",
        count($generatedFiles) - count($different) - count($missingInConsumer),
        count($different),
        count($missingInConsumer),
        count($staleInConsumer),
        $loaderDiffers ? 'DIFFERENT' : 'identical',
    );
    foreach (array_slice($different, 0, (int)$options['max-diffs']) as $relative) {
        echo "  --- $relative\n";
        $diff = [];
        exec(sprintf('diff -u %s %s | head -24', escapeshellarg($compareDir . $relative), escapeshellarg("$workDir/php$relative")), $diff);
        echo '    ' . implode("\n    ", array_slice($diff, 2)) . "\n";
    }
    foreach (["not in $compareLabel" => $missingInConsumer, "only in $compareLabel" => $staleInConsumer] as $label => $list) {
        if ($list) {
            echo "  $label: " . implode(', ', array_slice($list, 0, 8)) . (count($list) > 8 ? ', ...' : '') . "\n";
        }
    }
    if ($hasBaseline && ($different || $missingInConsumer || $staleInConsumer || $loaderDiffers)) {
        $failed[] = 'diff';
    }

    // 3. load
    $loadPaths = array_merge([$phpDir], array_map(fn (string $p) => absolutePath($consumerRoot, $p), $options['load']));
    $classes = [];
    foreach ($loadPaths as $path) {
        foreach (is_dir($path) ? listPhpFiles($path) : [$path] as $file) {
            $class = findDeclaredClass($file);
            if ($class) {
                $classes[$class] = $file;
            }
        }
    }
    $job = [
        'mode' => 'load',
        'ormRoot' => $ormRoot,
        'consumerRoot' => $consumerRoot,
        'phpDir' => $phpDir,
        'freshDir' => $options['generated'] === 'fresh' ? "$workDir/php" : null,
        'classes' => array_keys($classes),
    ];
    $loadErrors = runChildJob($job, $workDir);
    $baselineLoadErrors = $hasBaseline ? readList("$baselineDir/load-errors.txt") : [];
    $newLoadErrors = array_diff_key($loadErrors, array_flip($baselineLoadErrors));
    printf(
        "[load]  %d classes, %d failed, %d of them not in baseline (generated classes from %s)\n",
        count($classes),
        count($loadErrors),
        count($newLoadErrors),
        $options['generated'],
    );
    foreach (array_slice($saveBaseline ? [] : $newLoadErrors, 0, 15, true) as $class => $message) {
        echo "  $class: $message\n";
    }
    if ($newLoadErrors && !$saveBaseline) {
        $failed[] = 'load';
    }

    // 4. sql
    $job['mode'] = 'sql';
    $job['loaderScript'] = "$workDir/conf/loadDatabase.php";
    $job['resultFile'] = "$workDir/sql-current.txt";
    $sqlErrors = runChildJob($job, $workDir);
    $currentSql = is_file($job['resultFile']) ? (string)file_get_contents($job['resultFile']) : '';
    $statementCount = substr_count($currentSql, "\n");
    $baselineSqlErrors = $hasBaseline ? readList("$baselineDir/sql-errors.txt") : [];
    $newSqlErrors = array_diff_key($sqlErrors, array_flip($baselineSqlErrors));
    $changedSql = [];
    if ($hasBaseline) {
        $baselineLines = explode("\n", (string)file_get_contents("$baselineDir/sql.txt"));
        $currentLines = explode("\n", $currentSql);
        $changedSql = array_merge(array_diff($currentLines, $baselineLines), array_diff($baselineLines, $currentLines));
    }
    printf(
        "[sql]   %d statements, %d tables failed, %d of them not in baseline, %d statements differ from baseline\n",
        $statementCount,
        count($sqlErrors),
        count($newSqlErrors),
        count($changedSql),
    );
    foreach (array_slice($saveBaseline ? [] : $newSqlErrors, 0, 15, true) as $subject => $message) {
        echo "  $subject: $message\n";
    }
    foreach (array_slice($changedSql, 0, 10) as $line) {
        echo '  ' . substr($line, 0, 300) . "\n";
    }
    if (!$saveBaseline && ($newSqlErrors || $changedSql)) {
        $failed[] = 'sql';
    }

    if ($saveBaseline) {
        removeDir($baselineDir);
        mkdir("$baselineDir/conf", 0777, true);
        copyDir("$workDir/php", "$baselineDir/php");
        copy("$workDir/conf/loadDatabase.php", "$baselineDir/conf/loadDatabase.php");
        file_put_contents("$baselineDir/load-errors.txt", implode("\n", array_keys($loadErrors)));
        file_put_contents("$baselineDir/sql-errors.txt", implode("\n", array_keys($sqlErrors)));
        file_put_contents("$baselineDir/sql.txt", $currentSql);
        echo "\nBaseline saved to $baselineDir\n";

        return 0;
    }

    echo $failed ? "\nFAILED: " . implode(', ', $failed) . "\n" : "\nOK\n";

    return $failed ? 1 : 0;
}

/**
 * Runs a job in child processes. A fatal error (i.e. an incompatible method signature) kills
 * the child, so it is restarted behind the item that failed.
 *
 * @param array<string, mixed> $job
 * @param string $workDir
 *
 * @return array<string, string> Error message by class name
 */
function runChildJob(array $job, string $workDir): array
{
    $errors = [];
    $jobFile = "$workDir/job.json";
    $job['start'] = 0;
    $job['append'] = false;
    $itemCount = count($job['classes']);

    while ($job['start'] < $itemCount) {
        file_put_contents($jobFile, json_encode($job));
        $output = [];
        $exitCode = 0;
        exec(sprintf('%s -d memory_limit=-1 %s --child %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(__FILE__), escapeshellarg($jobFile)), $output, $exitCode);

        $current = null;
        $tail = [];
        foreach ($output as $line) {
            if (preg_match('/^@@START (\d+)$/', $line, $m)) {
                $current = (int)$m[1];
                $tail = [];
            } elseif (preg_match('/^@@ERROR (\d+) (.*)$/', $line, $m)) {
                $errors[$job['classes'][(int)$m[1]]] = $m[2];
            } elseif ($line === '@@DONE') {
                return $errors;
            } elseif (trim($line) !== '' && !preg_match('/^(PHP )?Deprecated:/', $line)) {
                $tail[] = trim($line);
            }
        }
        if ($current === null) {
            $errors['(child process)'] = implode(' | ', array_slice($tail, 0, 3)) ?: "exit code $exitCode";

            return $errors;
        }
        $errors[$job['classes'][$current]] = implode(' | ', array_slice($tail, 0, 2)) ?: "child died with exit code $exitCode";
        $job['start'] = $current + 1;
        $job['append'] = true;
    }

    return $errors;
}

/**
 * @param string $jobFile
 *
 * @return int
 */
function runChild(string $jobFile): int
{
    /** @var array<string, mixed> $job */
    $job = json_decode((string)file_get_contents($jobFile), true);
    $ormRoot = $job['ormRoot'];
    $phpDir = $job['phpDir'];
    $freshDir = $job['freshDir'];

    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    ini_set('display_errors', '1');

    /** @var \Composer\Autoload\ClassLoader $composer */
    $composer = require $job['consumerRoot'] . '/vendor/autoload.php';
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
        if ($file && str_starts_with($file, $phpDir) && preg_match(GENERATED_DIR_PATTERN, $file)) {
            $freshFile = $freshDir . substr($file, strlen($phpDir));
            if (is_file($freshFile)) {
                require $freshFile;
            }
        }
    }, true, true);

    $classes = $job['classes'];

    if ($job['mode'] === 'load') {
        for ($i = $job['start']; $i < count($classes); $i++) {
            echo "@@START $i\n";
            try {
                $class = $classes[$i];
                if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
                    echo "@@ERROR $i not found by autoloader\n";
                }
            } catch (Throwable $e) {
                echo "@@ERROR $i " . get_class($e) . ': ' . str_replace("\n", ' ', $e->getMessage()) . "\n";
            }
        }
        echo "@@DONE\n";

        return 0;
    }

    // mode sql
    $serviceContainer = \Propel\Runtime\Propel::getServiceContainer();
    require $job['loaderScript'];
    $resultFile = fopen($job['resultFile'], $job['append'] ? 'a' : 'w');
    $knownDatabases = [];

    for ($i = $job['start']; $i < count($classes); $i++) {
        $class = $classes[$i];
        if (!preg_match('#\\\\Map\\\\\w+TableMap$#', $class)) {
            continue;
        }
        echo "@@START $i\n";
        try {
            $databaseName = $class::DATABASE_NAME;
            if (!isset($knownDatabases[$databaseName])) {
                $serviceContainer->setAdapterClass($databaseName, 'mysql');
                $knownDatabases[$databaseName] = true;
            }
            $tableMap = $class::getTableMap();
            $modelClass = ltrim($class::OM_CLASS, '\\');
            $queryClass = $modelClass . 'Query';
            $lines = [];
            $params = [];
            $selectQuery = $queryClass::create();
            $class::addSelectColumns($selectQuery);
            $lines[] = "$modelClass|select|" . $selectQuery->createSelectSql($params);
            foreach ($tableMap->getRelations() as $relationName => $relation) {
                foreach (["join$relationName" => 'join', "leftJoin$relationName" => 'leftJoin'] as $method => $label) {
                    $params = [];
                    $lines[] = "$modelClass|$label:$relationName|" . $queryClass::create()->$method()->createSelectSql($params);
                }
                $params = [];
                $useMethod = "use{$relationName}Query";
                $lines[] = "$modelClass|use:$relationName|" . $queryClass::create()->$useMethod('rel_alias')->endUse()->createSelectSql($params);
            }
            fwrite($resultFile, implode("\n", array_map(fn (string $line) => (string)preg_replace('/\s+/', ' ', $line), $lines)) . "\n");
        } catch (Throwable $e) {
            echo "@@ERROR $i " . get_class($e) . ': ' . str_replace("\n", ' ', $e->getMessage()) . "\n";
        }
    }
    fclose($resultFile);
    echo "@@DONE\n";

    return 0;
}

/**
 * @param string $consumerRoot
 *
 * @return array<string, mixed>
 */
function loadConsumerConfig(string $consumerRoot): array
{
    foreach (['perpl.php', 'propel.php', 'perpl.php.dist', 'propel.php.dist'] as $name) {
        if (is_file("$consumerRoot/$name")) {
            $cwd = getcwd();
            chdir($consumerRoot);
            $config = include "$consumerRoot/$name";
            chdir((string)$cwd);

            return is_array($config) ? $config : [];
        }
    }

    return [];
}

/**
 * @param string $root
 * @param string $path
 *
 * @return string
 */
function absolutePath(string $root, string $path): string
{
    $absolute = str_starts_with($path, '/') ? $path : "$root/$path";

    return rtrim(realpath($absolute) ?: $absolute, '/');
}

/**
 * @param string $dir
 *
 * @return array<string>
 */
function listPhpFiles(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

/**
 * @param string $file
 *
 * @return string|null Fully qualified name of the first class-like declared in the file
 */
function findDeclaredClass(string $file): ?string
{
    $source = (string)file_get_contents($file);
    if (!preg_match('/^\s*(?:abstract\s+|final\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $source, $classMatch)) {
        return null;
    }
    $namespace = preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $source, $namespaceMatch) ? $namespaceMatch[1] . '\\' : '';

    return $namespace . $classMatch[1];
}

/**
 * @param string $file
 *
 * @return array<string>
 */
function readList(string $file): array
{
    return is_file($file) ? array_values(array_filter(explode("\n", (string)file_get_contents($file)))) : [];
}

/**
 * @param string $from
 * @param string $to
 *
 * @return void
 */
function copyDir(string $from, string $to): void
{
    foreach (listPhpFiles($from) as $file) {
        $target = $to . substr($file, strlen($from));
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        copy($file, $target);
    }
}

/**
 * @param string $dir
 *
 * @return void
 */
function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}
