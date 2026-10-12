<?php

declare(strict_types = 1);

namespace Propel\Common\Config\Loader;

use Generator;
use Propel\Common\Config\Exception\InvalidArgumentException;
use Propel\Common\Config\Exception\RuntimeException;
use function array_map;
use function dirname;
use function getenv;
use function gettype;
use function is_array;
use function is_dir;
use function is_float;
use function is_int;
use function is_string;
use function preg_replace_callback;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function substr;

/**
 * The resolve method and correlatives, with parameters between placeholders %name%, are heavily inspired to
 * Symfony\Component\DependencyInjection\ParameterBag class.
 */
class ConfigValueResolver
{
    /**
     * Configuration values array.
     * It contains the configuration values array to manipulate while resolving parameters.
     * It's useful, in particular, resolve() and get() method.
     *
     * @var array
     */
    private readonly array $config;

    /**
     * Directory of config file, used to replace __DIR__
     *
     * @var string
     */
    protected string $dir;

    /**
     * @param array $rawConfig
     * @param string $path
     *
     * @return array
     */
    public static function resolve(array $rawConfig, string $path): array
    {
        return (new self($rawConfig, $path))->resolveParams();
    }

    /**
     * @param array $rawConfig
     * @param string $path
     */
    public function __construct(array $rawConfig, string $path)
    {
        $this->config = $rawConfig;
        $this->dir = is_dir($path) ? $path : dirname($path);
    }

    /**
     * Replaces parameter placeholders (%name%) by their values for all parameters.
     *
     * @return array
     */
    public function resolveParams(): array
    {
        $parameters = [];
        foreach ($this->config as $key => $value) {
            $key = $this->resolveValue($key);
            $value = $this->resolveValue($value);
            $parameters[$key] = $this->unescapeValue($value); // only on outmost value ?!?
        }

        return $parameters;
    }

    /**
     * Replaces parameter placeholders (%name%) by their values.
     *
     * @param mixed $value The value to be resolved
     * @param array $resolving An array of keys that are being resolved (used internally to detect circular references)
     *
     * @return mixed The resolved value
     */
    private function resolveValue($value, array $resolving = [])
    {
        if (is_string($value)) {
            return $this->resolveString($value, $resolving);
        }

        if (!is_array($value)) {
            return $value;
        }

        $args = [];
        foreach ($value as $k => $v) {
            $resolvedKey = $this->resolveString((string)$k, $resolving);
            $args[$resolvedKey] = $this->resolveValue($v, $resolving);
        }

        return $args;
    }

    /**
     * Resolves parameters inside a string
     *
     * @param string $configurationLiteral The string to resolve
     * @param array $resolving An array of keys that are being resolved (used internally to detect circular references)
     *
     * @return mixed The resolved value
     */
    private function resolveString(string $configurationLiteral, array $resolving = [])
    {
        $configurationLiteral = str_replace('__DIR__', $this->dir, $configurationLiteral);

        /*
         * %%: to be unescaped
         * %[^%\s]++%: a parameter
         *         ^ backtracking is turned off
         * when it matches the entire $value, it can resolve to any value.
         * otherwise, it is replaced with the resolved string or number.
         */
        $mightReturnArray = false;
        $replaced = preg_replace_callback('/%([^%\s]*+)%/', function ($match) use ($resolving, $configurationLiteral, &$mightReturnArray) {
            $placeholder = $match[1];
            // skip %%
            if ($placeholder === '') {
                return '%%';
            }

            $env = $this->parseEnvironmentParams($placeholder);
            if ($env !== null) {
                return $env;
            }

            if (isset($resolving[$placeholder])) {
                throw new RuntimeException("Circular reference detected for parameter '$placeholder'.");
            }

            if ($configurationLiteral === $match[0]) {
                // whole value is a reference to another value, like value of "phpDir=%outputDir%"
                // this could be replace with an array, so it has to be handled outside
                $mightReturnArray = true;

                return $placeholder;
            }

            $resolved = $this->findKeyInConfig($placeholder);

            if (!is_string($resolved) && !is_int($resolved) && !is_float($resolved)) {
                throw new RuntimeException(sprintf('A string value must be composed of strings and/or numbers, but found parameter "%s" of type %s inside string value "%s".', $placeholder, gettype($resolved), $configurationLiteral));
            }

            $resolving[$placeholder] = true;

            return $this->resolveString((string)$resolved, $resolving);
        }, $configurationLiteral);

        if (!$mightReturnArray) {
            return $replaced;
        }

        $resolving[$replaced] = true;

        return $this->resolveValue($this->findKeyInConfig($replaced), $resolving);
    }

    /**
     * Return unescaped variable.
     *
     * @param mixed $value The variable to unescape
     *
     * @return mixed|array
     */
    private function unescapeValue($value)
    {
        if (is_string($value)) {
            return str_replace('%%', '%', $value);
        }

        return is_array($value)
            ? array_map($this->unescapeValue(...), $value)
            : $value;
    }

    /**
     * Return the value correspondent to a given key.
     *
     * @param mixed $propertyKey The key, in the configuration values array, to return the respective value
     *
     * @throws \Propel\Common\Config\Exception\InvalidArgumentException when non-existent key in configuration array
     *
     * @return mixed
     */
    private function findKeyInConfig($propertyKey)
    {
        $value = $this->findValue($propertyKey, $this->config);

        if (!$value->valid()) {
            throw new InvalidArgumentException("Parameter '$propertyKey' not found in configuration file.");
        }

        return $value->current();
    }

    /**
     * Scan recursively an array to find a value of a given key.
     *
     * @param string $propertyKey The array key
     * @param array $config The array to scan
     *
     * @return \Generator The value or null if not found
     */
    private function findValue(string $propertyKey, array $config): Generator
    {
        foreach ($config as $key => $value) {
            if ($key === $propertyKey) {
                yield $value;
            }
            if (is_array($value)) {
                yield from $this->findValue($propertyKey, $value);
            }
        }
    }

    /**
     * Check if the parameter contains an environment variable and parse it
     *
     * @param string $value The value to parse
     *
     * @throws \Propel\Common\Config\Exception\InvalidArgumentException if the environment variable is not set
     *
     * @return string|null
     */
    private function parseEnvironmentParams(string $value): ?string
    {
        // env.variable is an environment variable
        if (!str_starts_with($value, 'env.')) {
            return null;
        }
        $env = substr($value, 4);

        $envParam = getenv($env);
        if ($envParam === false) {
            throw new InvalidArgumentException("Environment variable '$env' is not defined.");
        }

        return $envParam;
    }
}
