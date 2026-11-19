<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Audit;
use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\ImprovementAction;
use App\Entity\Indicator;
use App\Repository\AuditRepository;
use App\Repository\FindingRepository;
use App\Repository\ImprovementActionRepository;
use App\Repository\IndicatorRepository;
use App\Security\Voter\QualityVoter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "Mejora continua" groups of the ⌘K palette: findings (fichas), improvement actions, indicators and audits.
 *
 * What a person may open there is not the same for everyone (a reporter only sees their own finding, an auditor only
 * the audits they are in), so each candidate goes through the same voter attribute that guards its own page before it
 * is listed — the queries over-fetch for that and the list is cut afterwards.
 */
final class QualitySearcher
{
    /** Results shown per group. */
    public const int PER_GROUP = 5;

    /** Candidates read per group before the permission filter cuts them to PER_GROUP. */
    private const int CANDIDATES = 30;

    public function __construct(
        private readonly FindingRepository $findings,
        private readonly ImprovementActionRepository $actions,
        private readonly IndicatorRepository $indicators,
        private readonly AuditRepository $audits,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {}

    /** @return array<string, list<array{label: string, sublabel: string, url: string}>> only the groups that have results */
    public function groups(EducationalCentre $centre, string $query): array
    {
        $groups = [];

        $items = [];
        foreach ($this->findings->searchByCentre($centre, $query, self::CANDIDATES) as $finding) {
            if (\count($items) < self::PER_GROUP && $this->authorization->isGranted(QualityVoter::FINDING_VIEW, $finding)) {
                $items[] = $this->findingItem($finding);
            }
        }
        if ($items !== []) {
            $groups['findings'] = $items;
        }

        $items = [];
        foreach ($this->actions->searchByCentre($centre, $query, self::CANDIDATES) as $action) {
            if (\count($items) < self::PER_GROUP && $this->authorization->isGranted(QualityVoter::ACTION_VIEW, $action)) {
                $items[] = $this->actionItem($action);
            }
        }
        if ($items !== []) {
            $groups['improvement_actions'] = $items;
        }

        $items = [];
        foreach ($this->indicators->searchByCentre($centre, $query, self::CANDIDATES) as $indicator) {
            if (\count($items) < self::PER_GROUP && $this->authorization->isGranted(QualityVoter::INDICATOR_VIEW, $indicator)) {
                $items[] = $this->indicatorItem($indicator);
            }
        }
        if ($items !== []) {
            $groups['indicators'] = $items;
        }

        $items = [];
        foreach ($this->audits->searchByCentre($centre, $query, self::CANDIDATES) as $audit) {
            if (\count($items) < self::PER_GROUP && $this->authorization->isGranted(QualityVoter::AUDIT_VIEW, $audit)) {
                $items[] = $this->auditItem($audit);
            }
        }
        if ($items !== []) {
            $groups['audits'] = $items;
        }

        return $groups;
    }

    /** @return array{label: string, sublabel: string, url: string} */
    private function findingItem(Finding $finding): array
    {
        $code   = $finding->getCode();
        $status = $this->translator->trans('status.' . $finding->getStatus()->value, [], 'quality');

        return [
            'label'    => $finding->getTitle(),
            'sublabel' => $code === null ? $status : $code . ' · ' . $status,
            'url'      => $this->urls->generate('app_quality_finding', ['id' => $finding->getId()->toRfc4122()]),
        ];
    }

    /** @return array{label: string, sublabel: string, url: string} */
    private function actionItem(ImprovementAction $action): array
    {
        $code   = $action->getCode();
        $status = $this->translator->trans('action_status.' . $action->getStatus()->value, [], 'quality');

        return [
            'label'    => self::shorten($action->getDescription()),
            'sublabel' => $code === null ? $status : $code . ' · ' . $status,
            'url'      => $this->urls->generate('app_quality_action', ['id' => $action->getId()->toRfc4122()]),
        ];
    }

    /** @return array{label: string, sublabel: string, url: string} */
    private function indicatorItem(Indicator $indicator): array
    {
        return [
            'label'    => $indicator->getName(),
            'sublabel' => self::shorten($indicator->getDescription() ?? ''),
            'url'      => $this->urls->generate('app_quality_indicator', ['id' => $indicator->getId()->toRfc4122()]),
        ];
    }

    /** @return array{label: string, sublabel: string, url: string} */
    private function auditItem(Audit $audit): array
    {
        return [
            'label'    => $audit->getTitle(),
            'sublabel' => $audit->getCode() . ' · ' . $this->translator->trans('audit.status.' . $audit->getStatus()->value, [], 'quality'),
            'url'      => $this->urls->generate('app_quality_audit', ['id' => $audit->getId()->toRfc4122()]),
        ];
    }

    private static function shorten(string $text): string
    {
        return mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 0, 90, '…');
    }
}
