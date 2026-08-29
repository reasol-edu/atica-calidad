<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\PersonName;
use PHPUnit\Framework\TestCase;

final class PersonNameTest extends TestCase
{
    public function testFullConcatenatesFirstAndLastNameWithASpace(): void
    {
        $name = new PersonName('Ana', 'García López');

        self::assertSame('Ana García López', $name->full());
    }

    public function testGettersReturnTheConstructorValues(): void
    {
        $name = new PersonName('Ana', 'García');

        self::assertSame('Ana', $name->getFirstName());
        self::assertSame('García', $name->getLastName());
    }

    public function testSettersReplaceTheStoredValues(): void
    {
        $name = new PersonName('Ana', 'García');

        $name->setFirstName('Luis')->setLastName('Pérez');

        self::assertSame('Luis Pérez', $name->full());
    }
}
