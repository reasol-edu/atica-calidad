<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\DayDetailBuilder;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CalendarController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    #[Route('/calendario', name: 'app_calendar')]
    public function index(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $isAdmin      = $this->isGranted(EducationalCentreVoter::SECTION, $centre);
        $requestedTab = $request->query->getString('tab');
        $tab          = $isAdmin && $requestedTab === 'events' ? 'events' : 'calendar';

        return $this->render('calendar/index.html.twig', [
            'centre'  => $centre,
            'isAdmin' => $isAdmin,
            'tab'     => $tab,
        ]);
    }

    #[Route('/calendario/dia/{date}', name: 'app_calendar_day', requirements: ['date' => '\d{4}-\d{2}-\d{2}'])]
    public function day(string $date, DayDetailBuilder $dayDetailBuilder, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $parsedDate = $this->parseDate($date);
        if ($parsedDate === null) {
            throw $this->createNotFoundException();
        }

        $academicYear = $this->tenantContext->getViewYear($centre);
        if ($academicYear === null) {
            throw $this->createNotFoundException();
        }

        $isAdmin = $this->isGranted(EducationalCentreVoter::SECTION, $centre);
        $user    = $this->getUser();
        $viewer  = $user instanceof Teacher ? $user : null;

        $report = $dayDetailBuilder->build($academicYear, $centre, $viewer, $isAdmin, $parsedDate);

        return $this->render('calendar/day.html.twig', [
            'centre'   => $centre,
            'date'     => $parsedDate,
            'isAdmin'  => $isAdmin,
            'report'   => $report,
            'prevDate' => $parsedDate->modify('-1 day')->format('Y-m-d'),
            'nextDate' => $parsedDate->modify('+1 day')->format('Y-m-d'),
        ]);
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false ? $date : null;
    }
}
