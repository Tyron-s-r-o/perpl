<?php

declare(strict_types = 1);

namespace Propel\Generator\Builder\Util;

use function array_pop;
use function array_slice;
use function count;
use function explode;
use function implode;
use function in_array;
use function ltrim;
use function preg_match;
use function preg_quote;
use function rtrim;
use function sort;
use function str_contains;
use function str_starts_with;
use function strpbrk;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Removes docblock tags from generated code which repeat what the native
 * declaration already says: `@param` and `@return` with the type of the
 * signature, `@var` with the type of the property, and `@throws`.
 *
 * Tags with more information than the native type (generics, array shapes,
 * other unions) are kept, as are descriptions and all other tags.
 */
class DocblockCompactor
{
    /**
     * @param string $script
     *
     * @return string
     */
    public static function compact(string $script): string
    {
        $lines = explode("\n", $script);
        $lineCount = count($lines);
        $output = [];

        for ($i = 0; $i < $lineCount; $i++) {
            $line = $lines[$i];
            if (trim($line) !== '/**') {
                $output[] = $line;

                continue;
            }

            $docblock = [$line];
            for ($i++; $i < $lineCount; $i++) {
                $docblock[] = $lines[$i];
                if (str_contains($lines[$i], '*/')) {
                    break;
                }
            }

            $declaration = static::readDeclaration($lines, $i + 1);
            if ($declaration !== null) {
                $docblock = static::compactDocblock($docblock, $declaration);
            }
            foreach ($docblock as $docblockLine) {
                $output[] = $docblockLine;
            }
        }

        return implode("\n", $output);
    }

    /**
     * Reads the function signature or property declaration following a docblock.
     *
     * @param array<string> $lines
     * @param int $start
     *
     * @return string|null
     */
    protected static function readDeclaration(array $lines, int $start): ?string
    {
        $lineCount = count($lines);
        while ($start < $lineCount && str_starts_with(ltrim($lines[$start]), '#[')) {
            $start++;
        }
        if ($start >= $lineCount) {
            return null;
        }

        $first = ltrim($lines[$start]);
        if (preg_match('/^(?:(?:public|protected|private|static|abstract|final)\s+)*function\s/', $first)) {
            $signature = '';
            for ($i = $start; $i < $lineCount && $i < $start + 40; $i++) {
                $signature .= ' ' . trim($lines[$i]);
                if (strpbrk($lines[$i], '{;') !== false) {
                    return $signature;
                }
            }

            return $i === $lineCount ? $signature : null;
        }
        if (preg_match('/^(?:public|protected|private)\s+(?:static\s+|readonly\s+)*[^(]*\$\w+\s*(?:=|;)/', $first)) {
            return $first;
        }

        return null;
    }

    /**
     * @param array<string> $docblock
     * @param string $declaration
     *
     * @return array<string>
     */
    protected static function compactDocblock(array $docblock, string $declaration): array
    {
        $isFunction = (bool)preg_match('/\bfunction\s/', $declaration);
        $returnType = null;
        if ($isFunction && preg_match('/\)\s*:\s*([?\\\\\w|]+)\s*[{;]?\s*$/', $declaration, $match)) {
            $returnType = $match[1];
        }

        $kept = [];
        $changed = false;
        foreach ($docblock as $line) {
            if (!preg_match('/^\s*\*\s*@(param|return|throws|var)\b\s*(\S+)?\s*(\$\w+)?/', $line, $match)) {
                $kept[] = $line;

                continue;
            }
            $tag = $match[1];
            $docType = $match[2] ?? '';
            $isRedundant = false;
            if ($tag === 'throws') {
                $isRedundant = true;
            } elseif ($tag === 'return' && $returnType !== null) {
                $isRedundant = static::isSameType($docType, $returnType)
                    || (in_array($docType, ['$this', 'static'], true) && in_array($returnType, ['static', 'self'], true));
            } elseif ($tag === 'param' && $isFunction && isset($match[3])) {
                $pattern = '/([?\\\\\w|]+)\s+(?:&|\.\.\.)*' . preg_quote($match[3], '/') . '\b/';
                $isRedundant = preg_match($pattern, $declaration, $nativeMatch) && static::isSameType($docType, $nativeMatch[1]);
            } elseif ($tag === 'var' && !$isFunction) {
                $isRedundant = preg_match('/^(?:public|protected|private)\s+(?:static\s+|readonly\s+)*([?\\\\\w|]+)\s+\$/', $declaration, $nativeMatch)
                    && static::isSameType($docType, $nativeMatch[1]);
            }

            if ($isRedundant) {
                $changed = true;
            } else {
                $kept[] = $line;
            }
        }
        if (!$changed) {
            return $docblock;
        }

        return static::tidy($kept);
    }

    /**
     * Removes empty lines left behind, and the docblock if nothing is left.
     *
     * @param array<string> $docblock
     *
     * @return array<string>
     */
    protected static function tidy(array $docblock): array
    {
        $isEmptyLine = fn (string $line): bool => trim($line) === '*';
        $open = $docblock[0];
        $close = array_pop($docblock);
        $body = array_slice($docblock, 1);

        $tidied = [];
        foreach ($body as $line) {
            if ($isEmptyLine($line) && (!$tidied || $isEmptyLine($tidied[count($tidied) - 1]))) {
                continue;
            }
            $tidied[] = $line;
        }
        while ($tidied && $isEmptyLine($tidied[count($tidied) - 1])) {
            array_pop($tidied);
        }
        if (!$tidied) {
            return [];
        }

        return [$open, ...$tidied, (string)$close];
    }

    /**
     * @param string $docType
     * @param string $nativeType
     *
     * @return bool
     */
    protected static function isSameType(string $docType, string $nativeType): bool
    {
        if ($docType === '' || strpbrk($docType, '<>[]{}()&-$') !== false) {
            return false;
        }

        return static::normalizeType($docType) === static::normalizeType($nativeType);
    }

    /**
     * @param string $type
     *
     * @return string
     */
    protected static function normalizeType(string $type): string
    {
        $parts = [];
        if (str_starts_with($type, '?')) {
            $parts[] = 'null';
            $type = substr($type, 1);
        }
        foreach (explode('|', $type) as $part) {
            $part = rtrim($part, ',');
            $separatorPosition = strrpos($part, '\\');
            if ($separatorPosition !== false) {
                $part = substr($part, $separatorPosition + 1);
            }
            $lowercase = strtolower($part);
            $parts[] = match ($lowercase) {
                'boolean' => 'bool',
                'integer' => 'int',
                'double' => 'float',
                'int', 'float', 'string', 'bool', 'array', 'null', 'mixed', 'void', 'object', 'iterable', 'callable', 'static', 'self', 'never', 'true', 'false' => $lowercase,
                default => $part,
            };
        }
        sort($parts);

        return implode('|', $parts);
    }
}
