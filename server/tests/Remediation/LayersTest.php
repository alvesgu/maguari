<?php

declare(strict_types=1);

namespace Maguari\Server\Tests\Remediation;

use Maguari\Server\Tests\Support\DomainLayersTestCase;

/**
 * Remediation's Domain/ is pure, the same rule as Monitoring's
 * (DomainLayersTestCase).
 */
final class LayersTest extends DomainLayersTestCase
{
    protected static function context(): string
    {
        return 'Remediation';
    }
}
