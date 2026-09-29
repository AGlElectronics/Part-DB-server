<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

final class OrderCheckInException extends \RuntimeException
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        public readonly string $translationKey,
        public readonly array $parameters = [],
    ) {
        parent::__construct($translationKey);
    }
}
