<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Service\CentreOnboarding;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Home dashboard card "Primeros pasos": what a new centre still needs to be useful (CentreOnboarding), shown only to
 * whoever manages the centre (the same people who reach the «Centro» section) and only while something is pending.
 */
#[AsTwigComponent]
class DashboardOnboardingComponent extends AbstractController
{
    public EducationalCentre $centre;

    public function __construct(
        private readonly CentreOnboarding $onboarding,
    ) {}

    /**
     * Steps to show, or an empty list when the card must not appear (the user does not manage the centre, or all done).
     *
     * @return list<array{key: string, done: bool}>
     */
    public function getSteps(): array
    {
        if (!$this->isGranted('educational_centre.section', $this->centre)) {
            return [];
        }

        $steps = $this->onboarding->steps($this->centre);

        return array_filter($steps, static fn (array $s): bool => !$s['done']) === [] ? [] : $steps;
    }
}
