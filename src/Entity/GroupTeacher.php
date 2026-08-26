<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GroupTeacherRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GroupTeacherRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_group_teacher_subject', columns: ['group_id', 'teacher_id', 'subject'])]
class GroupTeacher
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'groupTeachers')]
    #[ORM\JoinColumn(nullable: false)]
    private Group $group;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Teacher $teacher;

    #[ORM\Column(length: 255)]
    private string $subject;

    public function __construct(Group $group, Teacher $teacher, string $subject)
    {
        $this->group   = $group;
        $this->teacher = $teacher;
        $this->subject = $subject;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGroup(): Group
    {
        return $this->group;
    }

    public function getTeacher(): Teacher
    {
        return $this->teacher;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }
}
