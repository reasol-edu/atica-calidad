<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CalendarFeedTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The secret in the address of a teacher's personal calendar feed (iCal) for one centre. A calendar
 * app subscribes to that address without a session, so the token is the only credential: anyone
 * holding it can read the teacher's deadlines, and generating a new one revokes the old address.
 */
#[ORM\Entity(repositoryClass: CalendarFeedTokenRepository::class)]
#[ORM\Table(name: 'calendar_feed_token')]
#[ORM\UniqueConstraint(name: 'uniq_calendar_feed_token', columns: ['token'])]
#[ORM\UniqueConstraint(name: 'uniq_calendar_feed_teacher_centre', columns: ['teacher_id', 'educational_centre_id'])]
class CalendarFeedToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Teacher $teacher;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EducationalCentre $educationalCentre;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $token;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Teacher $teacher, EducationalCentre $educationalCentre, \DateTimeImmutable $createdAt)
    {
        $this->teacher           = $teacher;
        $this->educationalCentre = $educationalCentre;
        $this->createdAt         = $createdAt;
        $this->token             = self::generate();
    }

    public static function generate(): string
    {
        return bin2hex(random_bytes(24));
    }

    /** Replaces the secret: the previous address stops working. */
    public function regenerate(\DateTimeImmutable $now): void
    {
        $this->token     = self::generate();
        $this->createdAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTeacher(): Teacher
    {
        return $this->teacher;
    }

    public function getEducationalCentre(): EducationalCentre
    {
        return $this->educationalCentre;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
