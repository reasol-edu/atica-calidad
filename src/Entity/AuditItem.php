<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One point of an audit's checklist: what to check (with the standard's clause and some guidance),
 * and — as the audit is carried out — its result, the evidence seen and any files. A
 * nonconformity also says how serious it is. When the report is issued, a nonconformity, an
 * observation or an improvement becomes a finding linked back to it (Finding::getAuditItem()).
 */
#[ORM\Entity]
class AuditItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Audit $audit;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $clause;

    #[ORM\Column(type: Types::TEXT)]
    private string $question;

    /** How to check it: what to ask for, what to look at. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $guidance;

    #[ORM\Column(nullable: true, enumType: AuditResult::class)]
    private ?AuditResult $result = null;

    /** Only for a nonconformity: minor or major. */
    #[ORM\Column(nullable: true, enumType: FindingSeverity::class)]
    private ?FindingSeverity $severity = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $evidence = null;

    /** @var Collection<int, QualityAttachment> */
    #[ORM\OneToMany(targetEntity: QualityAttachment::class, mappedBy: 'auditItem')]
    #[ORM\OrderBy(['uploadedAt' => 'ASC'])]
    private Collection $attachments;

    public function __construct(Audit $audit, int $position, ?string $clause, string $question, ?string $guidance)
    {
        $this->audit       = $audit;
        $this->position    = $position;
        $this->clause      = $clause;
        $this->question    = $question;
        $this->guidance    = $guidance;
        $this->attachments = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAudit(): Audit
    {
        return $this->audit;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getClause(): ?string
    {
        return $this->clause;
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function getGuidance(): ?string
    {
        return $this->guidance;
    }

    public function update(int $position, ?string $clause, string $question, ?string $guidance): static
    {
        $this->position = $position;
        $this->clause   = $clause;
        $this->question = $question;
        $this->guidance = $guidance;

        return $this;
    }

    public function getResult(): ?AuditResult
    {
        return $this->result;
    }

    public function getSeverity(): ?FindingSeverity
    {
        return $this->severity;
    }

    /** A nonconformity is minor unless said otherwise; any other result has no severity. */
    public function record(?AuditResult $result, ?FindingSeverity $severity, ?string $evidence): static
    {
        $this->result   = $result;
        $this->severity = $result === AuditResult::Nonconformity ? ($severity ?? FindingSeverity::Minor) : null;
        $this->evidence = $evidence;

        return $this;
    }

    public function getEvidence(): ?string
    {
        return $this->evidence;
    }

    /** @return Collection<int, QualityAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }
}
