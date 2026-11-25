<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\Teacher;
use App\Repository\TeacherRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The teachers that count for an element of an activity that asks for them (Activity::isTeachersDeliver()): those associated with it
 * or any of its ancestors (ListItem::getEffectiveTeachers()) who are active and in the centre's active course — the same criterion as
 * Asignar perfiles, where whoever is outside the course shows in red. One definition for SlotResponsibility and for
 * DocumentTreeAccessChecker (which SlotResponsibility depends on, so it cannot depend on it back).
 *
 * Holds the ids of the course's teachers per centre for the request: dropped by any flush (a teacher may have joined or left the
 * course) and by reset().
 */
#[AsDoctrineListener(event: Events::postFlush)]
final class LeafTeachers implements ResetInterface
{
    /** @var array<string, array<string, true>> per centre: ids of the active course's teachers */
    private array $courseIds = [];

    public function __construct(
        private readonly TeacherRepository $teachers,
    ) {}

    /**
     * @return list<Teacher> empty when the activity does not ask for them
     */
    public function of(Activity $activity, ListItem $leaf): array
    {
        return $activity->isTeachersDeliver() ? $this->ofLeaf($leaf, $activity->getCategory()->getEducationalCentre()) : [];
    }

    /**
     * The teachers of an element that count whatever the activity (the box is not looked at): what the activity form uses to say
     * how many elements have none before it is saved.
     *
     * @return list<Teacher>
     */
    public function ofLeaf(ListItem $leaf, EducationalCentre $centre): array
    {
        $result = [];
        foreach ($leaf->getEffectiveTeachers() as $teacher) {
            if ($this->isInCourse($teacher, $centre)) {
                $result[] = $teacher;
            }
        }

        return $result;
    }

    public function includes(Activity $activity, ListItem $leaf, Teacher $teacher): bool
    {
        foreach ($this->of($activity, $leaf) as $candidate) {
            if ($candidate->getId()->equals($teacher->getId())) {
                return true;
            }
        }

        return false;
    }

    /** Whether $teacher is active and in the active course of $centre — the one that counts for catalogue teachers. */
    public function isInCourse(Teacher $teacher, EducationalCentre $centre): bool
    {
        if (!$teacher->isActive()) {
            return false;
        }
        $year = $centre->getActiveAcademicYear();
        if ($year === null) {
            return false;
        }

        $ids = $this->courseIds[$centre->getId()->toRfc4122()] ??= $this->teachers->findIdsByAcademicYear($year);

        return isset($ids[$teacher->getId()->toRfc4122()]);
    }

    public function postFlush(): void
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->courseIds = [];
    }
}
