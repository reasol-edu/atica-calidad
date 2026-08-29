<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Repository\EducationalCentreRepository;
use App\Service\CentreProvisioner;
use App\Tests\Integration\RepositoryTestCase;

final class CentreProvisionerTest extends RepositoryTestCase
{
    public function testProvisionCreatesACentreWithAnActiveAcademicYear(): void
    {
        /** @var CentreProvisioner $provisioner */
        $provisioner = self::getContainer()->get(CentreProvisioner::class);

        $centre = $provisioner->provision('12345678', 'IES Ejemplo', 'Sevilla', '2025-2026');

        self::assertSame('12345678', $centre->getCode());
        self::assertSame('IES Ejemplo', $centre->getName());
        self::assertSame('Sevilla', $centre->getCity());
        $activeYear = $centre->getActiveAcademicYear();
        self::assertNotNull($activeYear);
        self::assertSame('2025-2026', $activeYear->getName());

        $this->em->clear();
        /** @var EducationalCentreRepository $centres */
        $centres  = self::getContainer()->get(EducationalCentreRepository::class);
        $reloaded = $centres->findByIdWithActiveYear($centre->getId()->toRfc4122());
        self::assertNotNull($reloaded);
        $reloadedYear = $reloaded->getActiveAcademicYear();
        self::assertNotNull($reloadedYear);
        self::assertSame('2025-2026', $reloadedYear->getName());
    }
}
