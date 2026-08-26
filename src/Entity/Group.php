<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GroupRepository::class)]
#[ORM\Table(name: '`group`')]
class Group
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $details = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Course $course;

    /** @var Collection<int, GroupTeacher> */
    #[ORM\OneToMany(targetEntity: GroupTeacher::class, mappedBy: 'group', cascade: ['persist'], orphanRemoval: true)]
    private Collection $groupTeachers;

    /** @var Collection<int, Teacher> */
    #[ORM\ManyToMany(targetEntity: Teacher::class, fetch: 'EXTRA_LAZY')]
    #[ORM\JoinTable(name: 'group_tutor')]
    private Collection $tutors;

    public function __construct()
    {
        $this->groupTeachers = new ArrayCollection();
        $this->tutors        = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(?string $details): static
    {
        $this->details = $details;

        return $this;
    }

    public function getCourse(): Course
    {
        return $this->course;
    }

    public function setCourse(Course $course): static
    {
        $this->course = $course;

        return $this;
    }

    public function getAcademicYear(): AcademicYear
    {
        return $this->course->getAcademicYear();
    }

    /**
     * @return Collection<int, GroupTeacher>
     */
    public function getTeacherAssignments(): Collection
    {
        return $this->groupTeachers;
    }

    /**
     * Distinct teachers assigned to this group, regardless of how many subjects each one teaches.
     *
     * @return Collection<int, Teacher>
     */
    public function getTeachers(): Collection
    {
        $teachers = [];
        foreach ($this->groupTeachers as $groupTeacher) {
            $teacher = $groupTeacher->getTeacher();
            $teachers[$teacher->getId()->toRfc4122()] = $teacher;
        }

        return new ArrayCollection(array_values($teachers));
    }

    public function hasTeacherSubject(Teacher $teacher, string $subject): bool
    {
        return $this->groupTeachers->exists(
            static fn (int $i, GroupTeacher $gt): bool =>
                $gt->getTeacher() === $teacher && $gt->getSubject() === $subject
        );
    }

    public function addTeacher(Teacher $teacher, string $subject): static
    {
        if (!$this->hasTeacherSubject($teacher, $subject)) {
            $this->groupTeachers->add(new GroupTeacher($this, $teacher, $subject));
        }

        return $this;
    }

    /**
     * Removes the teacher's assignment(s) from this group. If $subject is given, only that
     * subject is removed; otherwise every assignment of this teacher in this group is removed.
     */
    public function removeTeacher(Teacher $teacher, ?string $subject = null): static
    {
        foreach ($this->groupTeachers as $groupTeacher) {
            if ($groupTeacher->getTeacher() === $teacher
                && ($subject === null || $groupTeacher->getSubject() === $subject)
            ) {
                $this->groupTeachers->removeElement($groupTeacher);
            }
        }

        return $this;
    }

    public function removeTeacherAssignment(GroupTeacher $groupTeacher): static
    {
        $this->groupTeachers->removeElement($groupTeacher);

        return $this;
    }

    /**
     * @return Collection<int, Teacher>
     */
    public function getTutors(): Collection
    {
        return $this->tutors;
    }

    public function addTutor(Teacher $tutor): static
    {
        if (!$this->tutors->contains($tutor)) {
            $this->tutors->add($tutor);
        }

        return $this;
    }

    public function removeTutor(Teacher $tutor): static
    {
        $this->tutors->removeElement($tutor);

        return $this;
    }
}
