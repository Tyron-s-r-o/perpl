<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ConsumerContractHooks;

use ConsumerContractHooks\Base\HookedThing as BaseHookedThing;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * Stub class of the hook test model, overriding all hooks to record the call order.
 *
 * Loaded by HookTest after the base classes were built (QuickBuilder cannot generate custom stubs).
 */
class HookedThing extends BaseHookedThing
{
    /**
     * @var array<string>
     */
    public static array $log = [];

    /**
     * Name of the hook that should return false.
     *
     * @var string|null
     */
    public static ?string $abortAt = null;

    /**
     * @param string $hook
     *
     * @return bool
     */
    private static function record(string $hook): bool
    {
        static::$log[] = $hook;

        return static::$abortAt !== $hook;
    }

    public function preSave(?ConnectionInterface $con = null): bool
    {
        return static::record('preSave');
    }

    public function postSave(?ConnectionInterface $con = null): void
    {
        static::record('postSave');
    }

    public function preInsert(?ConnectionInterface $con = null): bool
    {
        return static::record('preInsert');
    }

    public function postInsert(?ConnectionInterface $con = null): void
    {
        static::record('postInsert');
    }

    public function preUpdate(?ConnectionInterface $con = null): bool
    {
        return static::record('preUpdate');
    }

    public function postUpdate(?ConnectionInterface $con = null): void
    {
        static::record('postUpdate');
    }

    public function preDelete(?ConnectionInterface $con = null): bool
    {
        return static::record('preDelete');
    }

    public function postDelete(?ConnectionInterface $con = null): void
    {
        static::record('postDelete');
    }
}
