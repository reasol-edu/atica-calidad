<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\RejectionReason;
use App\Repository\RejectionReasonRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The ready-made rejection reasons a centre offers its reviewers: its own list when it has set
 * one, else the standard set (translated under review.reason.*). Read once per request and centre
 * — the review queue asks for them on every render — and dropped by any flush.
 */
#[AsDoctrineListener(event: Events::postFlush)]
final class RejectionReasonProvider implements ResetInterface
{
    /** The standard reasons: translation keys under review.reason.*, in display order. */
    public const array DEFAULT_KEYS = ['format', 'incomplete', 'wrong_version', 'unsigned', 'unreadable', 'not_applicable'];

    /** @var array<string, list<string>> */
    private array $memo = [];

    public function __construct(
        private readonly RejectionReasonRepository $reasons,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {}

    public function postFlush(): void
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->memo = [];
    }

    /** @return list<string> */
    public function forCentre(EducationalCentre $centre): array
    {
        return $this->memo[$centre->getId()->toRfc4122()] ??= $this->load($centre);
    }

    /** Whether the centre has its own list (else the standard one applies). */
    public function isCustomised(EducationalCentre $centre): bool
    {
        return $this->reasons->findByCentre($centre) !== [];
    }

    /** @return list<string> */
    public function defaults(): array
    {
        return array_map(fn (string $key): string => $this->translator->trans('review.reason.' . $key, [], 'dashboard'), self::DEFAULT_KEYS);
    }

    /**
     * Replaces the centre's list with $texts, one per non-blank entry, trimmed, without repeats and
     * cut to the column's length. An empty result goes back to the standard set.
     *
     * @param list<string> $texts
     */
    public function replace(EducationalCentre $centre, array $texts): void
    {
        $clean = [];
        foreach ($texts as $text) {
            $text = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, RejectionReason::MAX_LENGTH);
            if ($text !== '' && !\in_array($text, $clean, true)) {
                $clean[] = $text;
            }
        }

        $this->reasons->deleteByCentre($centre);
        foreach ($clean as $position => $text) {
            $this->em->persist(new RejectionReason($centre, $text, $position));
        }
        $this->em->flush();
    }

    /** @return list<string> */
    private function load(EducationalCentre $centre): array
    {
        $own = array_map(static fn (RejectionReason $r): string => $r->getText(), $this->reasons->findByCentre($centre));

        return $own !== [] ? $own : $this->defaults();
    }
}
