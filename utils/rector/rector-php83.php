<?php

/**
 * One-off syntax modernisation to PHP 8.3.
 *
 * Run from the repository root with a rector binary (rector is not a dev dependency):
 *   rector process --config utils/rector/rector-php83.php
 * Run it until it reports no more changes, then run `composer cs-fix`.
 */

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php80\Rector\FuncCall\ClassOnObjectRector;
use Rector\Php80\Rector\Switch_\ChangeSwitchToMatchRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnNeverTypeRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/../../src',
    ])
    ->withSkip([
        '*/templates/*',
        // class names with and without leading backslash end up in generated code and config values
        StringClassNameToClassConstantRector::class,
        // moves property docblocks into constructor signatures and turns copied by-reference arguments into references
        ClassPropertyAssignToConstructorPromotionRector::class,
        // a method returning never cannot be overridden by one that returns
        ReturnNeverTypeRector::class,
        // arms need different argument types, which the type annotation in the switch documents
        ChangeSwitchToMatchRector::class => [
            '*/Runtime/Connection/StatementWrapper.php',
        ],
        // closure argument is documented as class-string|TableMap for psalm and has no native type
        ClassOnObjectRector::class => [
            '*/Runtime/Map/DatabaseMap.php',
        ],
    ])
    ->withPhpSets(php83: true);
