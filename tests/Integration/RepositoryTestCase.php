<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class RepositoryTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $em */
        $em       = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        (new SchemaTool($this->em))->createSchema(
            $this->em->getMetadataFactory()->getAllMetadata()
        );
    }

    protected function tearDown(): void
    {
        (new SchemaTool($this->em))->dropSchema(
            $this->em->getMetadataFactory()->getAllMetadata()
        );

        parent::tearDown();
    }

    /** Persiste todas las entidades pasadas y hace flush. */
    protected function persist(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    /**
     * Hace flush adicional.
     * Útil para registrar cambios en colecciones ManyToMany después de que
     * las entidades ya están gestionadas (PersistentCollection).
     */
    protected function flush(): void
    {
        $this->em->flush();
    }
}
