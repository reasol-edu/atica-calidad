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

    /** Persists all given entities and flushes. */
    protected function persist(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    /**
     * Performs an additional flush.
     * Useful for registering changes to ManyToMany collections after
     * the entities are already managed (PersistentCollection).
     */
    protected function flush(): void
    {
        $this->em->flush();
    }
}
