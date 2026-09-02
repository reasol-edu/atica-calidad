<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creates a new educational centre with its first academic year and its default
 * Responsabilidades › Listas roots.
 *
 * Shared by app:setup, app:create-educational-centre and app:load-demo-data (CLI), and by
 * EducationalCentreController (web) — the single place a centre gets created, so every one of
 * them gets the same default lists without duplicating this sequence.
 */
final class CentreProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {}

    public function provision(string $code, string $name, string $city, string $academicYearName): EducationalCentre
    {
        $centre = (new EducationalCentre())
            ->setCode($code)
            ->setName($name)
            ->setCity($city);

        $academicYear = (new AcademicYear())
            ->setName($academicYearName)
            ->setEducationalCentre($centre);

        $centre->setActiveAcademicYear($academicYear);

        $this->em->persist($centre);
        $this->em->persist($academicYear);
        $this->createDefaultListRoots($centre);

        $this->em->flush();

        return $centre;
    }

    /**
     * The default list root names come from a single semicolon-separated translation string
     * (editable without a code change) rather than one translation key per root, since the count
     * and the names are both meant to be configurable together.
     */
    private function createDefaultListRoots(EducationalCentre $centre): void
    {
        $names = array_filter(array_map(
            trim(...),
            explode(';', $this->translator->trans('responsibilities.lists.default_roots', [], 'admin')),
        ), static fn (string $name): bool => $name !== '');

        foreach (array_values($names) as $position => $name) {
            $root = new ListItem();
            $root->setName($name)->setEducationalCentre($centre)->setPosition($position);
            $this->em->persist($root);
        }
    }
}
