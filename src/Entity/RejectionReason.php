<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RejectionReasonRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One of the ready-made reasons a centre offers a reviewer to reject a submission with (review
 * queue). A centre without any uses the standard set (RejectionReasonProvider).
 */
#[ORM\Entity(repositoryClass: RejectionReasonRepository::class)]
#[ORM\Table(name: 'rejection_reason')]
#[ORM\Index(columns: ['educational_centre_id', 'position'], name: 'idx_rejection_reason_centre_position')]
class RejectionReason
{
    public const int MAX_LENGTH = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EducationalCentre $educationalCentre;

    #[ORM\Column(type: Types::STRING, length: self::MAX_LENGTH)]
    private string $text;

    #[ORM\Column(type: Types::INTEGER)]
    private int $position;

    public function __construct(EducationalCentre $educationalCentre, string $text, int $position)
    {
        $this->educationalCentre = $educationalCentre;
        $this->text              = $text;
        $this->position          = $position;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEducationalCentre(): EducationalCentre
    {
        return $this->educationalCentre;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
