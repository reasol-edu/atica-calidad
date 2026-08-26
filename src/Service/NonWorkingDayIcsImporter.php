<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\NonWorkingDay;
use App\Repository\NonWorkingDayRepository;
use Doctrine\ORM\EntityManagerInterface;
use ICal\Event;
use ICal\ICal;

class NonWorkingDayIcsImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NonWorkingDayRepository $nonWorkingDays,
    ) {}

    /** @return array{new: int, existing: int} */
    public function import(string $filePath, AcademicYear $year): array
    {
        $iCal = new ICal($filePath);

        $new      = 0;
        $existing = 0;
        $seen     = [];

        foreach ($iCal->events() as $event) {
            if (!$event instanceof Event || !is_string($event->dtstart)) {
                continue;
            }

            $date = \DateTimeImmutable::createFromMutable($iCal->iCalDateToDateTime($event->dtstart))
                ->setTime(0, 0);
            $dateKey = $date->format('Y-m-d');

            if (isset($seen[$dateKey]) || $this->nonWorkingDays->findByAcademicYearAndDate($year, $date) !== null) {
                $existing++;
                continue;
            }

            $seen[$dateKey] = true;

            $summary     = is_string($event->summary) ? $event->summary : '';
            $eventDesc   = is_string($event->description) ? $event->description : '';
            $description = trim($eventDesc !== '' ? $eventDesc : $summary);

            $nonWorkingDay = (new NonWorkingDay())
                ->setDate($date)
                ->setDescription($description !== '' ? $description : null)
                ->setAcademicYear($year);

            $this->em->persist($nonWorkingDay);
            $new++;
        }

        $this->em->flush();

        return ['new' => $new, 'existing' => $existing];
    }
}
