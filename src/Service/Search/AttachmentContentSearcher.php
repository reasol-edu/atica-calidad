<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\EducationalCentre;
use App\Entity\QualityAttachment;
use App\Repository\DocumentFileTextRepository;
use App\Repository\QualityAttachmentRepository;
use App\Search\SnippetBuilder;
use App\Search\TextNormalizer;
use App\Security\Voter\QualityVoter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Search inside the files attached to findings, improvement actions and internal-audit items ("Mejora continua"), for the ⌘K
 * palette. What a person may open there is decided by the very rule that guards the attachment's own download
 * (QualityController::download()): the finding it belongs to (directly or through its action), else its action, else its audit —
 * checked before any text is read.
 */
final class AttachmentContentSearcher
{
    /** Results returned. */
    public const int LIMIT = 3;

    private const int BATCH  = 30;
    private const int ROUNDS = 4;

    public function __construct(
        private readonly QualityAttachmentRepository $attachments,
        private readonly DocumentFileTextRepository $texts,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * @return list<array{label: string, sublabel: string, url: string, snippet: list<array{0: string, 1: bool}>}>
     */
    public function search(EducationalCentre $centre, string $query): array
    {
        $terms = DocumentContentSearcher::terms($query);
        if ($terms === []) {
            return [];
        }

        /** @var list<QualityAttachment> $found */
        $found  = [];
        $offset = 0;
        for ($round = 0; $round < self::ROUNDS && \count($found) < self::LIMIT; ++$round) {
            $batch = $this->attachments->searchContentByCentre($centre, $terms, self::BATCH, $offset);
            $offset += self::BATCH;

            foreach ($batch as $attachment) {
                if (\count($found) < self::LIMIT && $this->canSee($attachment, $centre)) {
                    $found[] = $attachment;
                }
            }
            if (\count($batch) < self::BATCH) {
                break;
            }
        }

        $texts = $this->texts->findTexts(array_map(static fn (QualityAttachment $a) => $a->getFile()->getId(), $found));

        $items = [];
        foreach ($found as $attachment) {
            $text = $texts[$attachment->getFile()->getId()->toRfc4122()] ?? null;
            if ($text === null) {
                continue;
            }
            $snippet = SnippetBuilder::build($text, TextNormalizer::fold($text), $terms);
            if ($snippet !== null) {
                $items[] = $this->item($attachment, $snippet);
            }
        }

        return $items;
    }

    /** The same checks, in the same order, as the attachment's download. */
    private function canSee(QualityAttachment $attachment, EducationalCentre $centre): bool
    {
        $finding = $attachment->getOwningFinding();
        $action  = $attachment->getAction();
        $audit   = $attachment->getAuditItem()?->getAudit();

        if ($finding !== null) {
            return $finding->getEducationalCentre() === $centre && $this->authorization->isGranted(QualityVoter::FINDING_VIEW, $finding);
        }
        if ($action !== null && $action->getEducationalCentre() === $centre) {
            return $this->authorization->isGranted(QualityVoter::ACTION_VIEW, $action);
        }
        if ($audit !== null && $audit->getEducationalCentre() === $centre) {
            return $this->authorization->isGranted(QualityVoter::AUDIT_VIEW, $audit);
        }

        return false;
    }

    /**
     * @param list<array{0: string, 1: bool}> $snippet
     *
     * @return array{label: string, sublabel: string, url: string, snippet: list<array{0: string, 1: bool}>}
     */
    private function item(QualityAttachment $attachment, array $snippet): array
    {
        $finding = $attachment->getOwningFinding();
        $action  = $attachment->getAction();
        $audit   = $attachment->getAuditItem()?->getAudit();

        if ($finding !== null && $attachment->getFinding() !== null) {
            $where = $this->translator->trans('attachment.of_finding', ['%title%' => $finding->getTitle()], 'search');
            $url   = $this->urls->generate('app_quality_finding', ['id' => $finding->getId()->toRfc4122(), '_fragment' => 'evidencias']);
        } elseif ($action !== null) {
            $where = $this->translator->trans('attachment.of_action', ['%title%' => mb_strimwidth($action->getDescription(), 0, 70, '…')], 'search');
            $url   = $this->urls->generate('app_quality_action', ['id' => $action->getId()->toRfc4122(), '_fragment' => 'evidencias']);
        } else {
            \assert($audit !== null);
            $where = $this->translator->trans('attachment.of_audit', ['%code%' => $audit->getCode(), '%title%' => $audit->getTitle()], 'search');
            $url   = $this->urls->generate('app_quality_audit', ['id' => $audit->getId()->toRfc4122()]);
        }

        return ['label' => $attachment->getFilename(), 'sublabel' => $where, 'url' => $url, 'snippet' => $snippet];
    }
}
