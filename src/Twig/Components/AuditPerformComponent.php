<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Audit;
use App\Entity\AuditItem;
use App\Entity\AuditResult;
use App\Entity\AuditStatus;
use App\Entity\FindingSeverity;
use App\Security\Voter\QualityVoter;
use App\Service\AuditService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Carrying out an audit, made for a tablet: its checklist, a point per card with big result
 * buttons and the evidence, then the strengths and the conclusion. Everything is saved as it's
 * filled in — a result on tapping it, a text on leaving it — so the audit can be left and resumed.
 * Only its team (or whoever manages) while it's in progress (QualityVoter::AUDIT_WORK).
 */
#[AsLiveComponent]
class AuditPerformComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public Audit $audit;

    /** @var array<int, string> the evidence of each point, by its position in the checklist */
    #[LiveProp(writable: true)]
    public array $evidence = [];

    #[LiveProp(writable: true)]
    public string $strengths = '';

    #[LiveProp(writable: true)]
    public string $conclusion = '';

    public function __construct(
        private readonly AuditService $audits,
    ) {}

    public function mount(Audit $audit): void
    {
        $this->audit = $audit;
        foreach ($this->items() as $i => $item) {
            $this->evidence[$i] = $item->getEvidence() ?? '';
        }
        $this->strengths  = $audit->getStrengths() ?? '';
        $this->conclusion = $audit->getConclusion() ?? '';
    }

    /** @return list<AuditItem> */
    public function items(): array
    {
        return array_values($this->audit->getItems()->toArray());
    }

    /** @return list<AuditResult> */
    public function getResults(): array
    {
        return AuditResult::cases();
    }

    /** @return list<string> why the report can't be issued yet */
    public function getBlockers(): array
    {
        return $this->audits->blockers($this->audit, 'issue_report');
    }

    /**
     * Action args arrive as request attributes: the index as a string.
     *
     * @param string $result a result value; the one already chosen clears it
     */
    #[LiveAction]
    public function setResult(#[LiveArg] string $index, #[LiveArg] string $result): void
    {
        $item   = $this->item($index);
        $chosen = AuditResult::tryFrom($result);
        $this->audits->record($item, $chosen === $item->getResult() ? null : $chosen, $item->getSeverity(), $this->evidence[(int) $index] ?? $item->getEvidence());
    }

    #[LiveAction]
    public function setSeverity(#[LiveArg] string $index, #[LiveArg] string $severity): void
    {
        $item = $this->item($index);
        $this->audits->record($item, $item->getResult(), FindingSeverity::tryFrom($severity), $item->getEvidence());
    }

    #[LiveAction]
    public function saveEvidence(#[LiveArg] string $index): void
    {
        $item = $this->item($index);
        $this->audits->record($item, $item->getResult(), $item->getSeverity(), $this->evidence[(int) $index] ?? '');
    }

    #[LiveAction]
    public function saveReport(): void
    {
        $this->guard();
        $this->audits->saveReport($this->audit, $this->strengths, $this->conclusion);
    }

    private function item(string $index): AuditItem
    {
        $this->guard();

        return $this->items()[(int) $index] ?? throw $this->createNotFoundException();
    }

    private function guard(): void
    {
        $this->denyAccessUnlessGranted(QualityVoter::AUDIT_WORK, $this->audit);
        if ($this->audit->getStatus() !== AuditStatus::InProgress) {
            throw $this->createAccessDeniedException();
        }
    }
}
