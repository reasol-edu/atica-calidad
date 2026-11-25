<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A teacher associated with a list item — the one who teaches that subject, group... In an activity
 * that asks for it (Activity::isTeachersDeliver()) the teachers of an element fill in and deliver the
 * document of that element. Like a profile association, it is inherited by everything under the
 * element (see ListItem::getEffectiveTeachers()) and does not depend on the academic year: whoever is
 * not in the active course simply does not count (see SlotResponsibility). Deleting the item or the
 * teacher removes the row.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uq_list_item_teacher', columns: ['list_item_id', 'teacher_id'])]
class ListItemTeacher
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'teacherAssociations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ListItem $listItem;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Teacher $teacher;

    public function __construct(ListItem $listItem, Teacher $teacher)
    {
        $this->listItem = $listItem;
        $this->teacher  = $teacher;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getListItem(): ListItem
    {
        return $this->listItem;
    }

    public function getTeacher(): Teacher
    {
        return $this->teacher;
    }
}
