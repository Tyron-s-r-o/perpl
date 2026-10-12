<?php

declare(strict_types = 1);

namespace Propel\Common\Config\Loader;

use Symfony\Component\Config\Loader\LoaderResolver as BaseLoaderResolver;

/**
 * Class LoaderResolver
 */
class LoaderResolver extends BaseLoaderResolver
{
    /**
     * @param array<\Symfony\Component\Config\Loader\LoaderInterface>|null $loaders
     */
    public function __construct(?array $loaders = null)
    {
        $loaders ??= [
            new PhpFileLoader(),
        ];

        parent::__construct($loaders);
    }
}
