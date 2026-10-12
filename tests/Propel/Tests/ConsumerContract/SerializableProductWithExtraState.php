<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\ConsumerContract;

use ConsumerContract\ContractProduct;

/**
 * Consumer model stubs declare properties of their own, also private ones.
 */
class SerializableProductWithExtraState extends ContractProduct
{
    private ?string $privateState = null;

    protected ?string $protectedState = null;

    public ?string $publicState = null;

    /**
     * @param string $private
     * @param string $protected
     * @param string $public
     *
     * @return void
     */
    public function setExtraState(string $private, string $protected, string $public): void
    {
        $this->privateState = $private;
        $this->protectedState = $protected;
        $this->publicState = $public;
    }

    /**
     * @return array<string|null>
     */
    public function getExtraState(): array
    {
        return [$this->privateState, $this->protectedState, $this->publicState];
    }
}
