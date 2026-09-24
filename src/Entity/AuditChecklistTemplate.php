<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditChecklistTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A checklist of the centre's library, to prepare audits from: usually one per clause of ISO 9001
 * (loaded from AuditChecklistLibrary), edited by the centre, or its own. Preparing an audit copies
 * its points into the audit, which can then adapt them without touching the library.
 */
#[ORM\Entity(repositoryClass: AuditChecklistTemplateRepository::class)]
class AuditChecklistTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EducationalCentre $educationalCentre;

    #[ORM\Column(length: 255)]
    private string $name;

    /** The clause it's about ("8.1"), to order and find it. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $clause;

    /**
     * Its points, as a list — they're only ever read and written whole.
     *
     * @var list<array{clause: ?string, question: string, guidance: ?string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $items = [];

    public function __construct(EducationalCentre $centre, string $name, ?string $clause)
    {
        $this->educationalCentre = $centre;
        $this->name              = $name;
        $this->clause            = $clause;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEducationalCentre(): EducationalCentre
    {
        return $this->educationalCentre;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getClause(): ?string
    {
        return $this->clause;
    }

    public function setClause(?string $clause): static
    {
        $this->clause = $clause;

        return $this;
    }

    /** @return list<array{clause: ?string, question: string, guidance: ?string}> */
    public function getItems(): array
    {
        return $this->items;
    }

    /** @param list<array{clause: ?string, question: string, guidance: ?string}> $items */
    public function setItems(array $items): static
    {
        $this->items = $items;

        return $this;
    }
}
