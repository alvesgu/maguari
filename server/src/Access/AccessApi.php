<?php

declare(strict_types=1);

namespace Maguari\Server\Access;

final class AccessApi
{
    public function __construct(
        private readonly SeedConfigReader $seedConfigReader = new SeedConfigReader(),
    ) {
    }

    public function readSeedConfig(): ?SeedConfig
    {
        return $this->seedConfigReader->read();
    }
}
