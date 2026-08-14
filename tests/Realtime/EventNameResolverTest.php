<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Tests\Realtime;

use PHPUnit\Framework\TestCase;
use Survos\DepotBundle\Realtime\Event\OcrCompleted;
use Survos\DepotBundle\Realtime\EventNameResolver;

final class EventNameResolverTest extends TestCase
{
    public function testResolveReturnsTheMappedDottedName(): void
    {
        $resolver = new EventNameResolver([
            OcrCompleted::class => 'asset.ocr.completed',
        ]);

        self::assertSame(
            'asset.ocr.completed',
            $resolver->resolve(new OcrCompleted('01K...', 1437)),
        );
    }

    public function testResolveThrowsForAnUnregisteredEventClass(): void
    {
        $resolver = new EventNameResolver();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/OcrCompleted/');

        $resolver->resolve(new OcrCompleted('01K...', 1437));
    }
}
