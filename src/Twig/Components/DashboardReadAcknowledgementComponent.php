<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Document;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Service\ReadAcknowledgementService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Home dashboard widget: the documents the teacher still has to acknowledge as read
 * (ReadAcknowledgementService), each linking to its place in the tree, where it's read and
 * confirmed. Renders nothing when there are none.
 */
#[AsTwigComponent]
class DashboardReadAcknowledgementComponent extends AbstractController
{
    public const int MAX_ITEMS = 8;

    public EducationalCentre $centre;

    /** @var list<Document>|null */
    private ?array $pending = null;

    public function __construct(
        private readonly ReadAcknowledgementService $readAcknowledgements,
    ) {}

    /** @return list<Document> */
    public function getPending(): array
    {
        if ($this->pending !== null) {
            return $this->pending;
        }

        $user = $this->getUser();

        return $this->pending = $user instanceof Teacher ? $this->readAcknowledgements->pendingFor($user, $this->centre) : [];
    }
}
