<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ActivityLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One append-only entry in the activity log: a single user action (or session event), who did it
 * and from which IP, for security auditing. Written after the response is sent
 * (ActivityLogSubscriber on kernel.terminate) so it never adds latency, and never updated or
 * edited once created — hence a constructor with every field and getters only.
 */
#[ORM\Entity(repositoryClass: ActivityLogRepository::class)]
#[ORM\Table(name: 'activity_log')]
#[ORM\Index(columns: ['created_at'], name: 'idx_al_created')]
#[ORM\Index(columns: ['active_user_id', 'created_at'], name: 'idx_al_user_created')]
#[ORM\Index(columns: ['action_type', 'created_at'], name: 'idx_al_type_created')]
#[ORM\Index(columns: ['educational_centre_id', 'created_at'], name: 'idx_al_centre_created')]
class ActivityLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 45)]
    private string $ip;

    /** The effective user: whoever the action was performed as (the impersonated user, if any). */
    #[ORM\ManyToOne(targetEntity: Teacher::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $activeUser = null;

    /** The real user behind an impersonation session; null when nobody is impersonating. */
    #[ORM\ManyToOne(targetEntity: Teacher::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $realUser = null;

    /** The centre the action pertains to; null for centre-less events (login, logout, centre CRUD). */
    #[ORM\ManyToOne(targetEntity: EducationalCentre::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EducationalCentre $educationalCentre = null;

    #[ORM\ManyToOne(targetEntity: AcademicYear::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AcademicYear $academicYear = null;

    #[ORM\Column(length: 100)]
    private string $actionType;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $route = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $method = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $statusCode = null;

    /** @var array<string, mixed>|null free-form context: entity name, folder path, file count, … */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $data = null;

    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(
        \DateTimeImmutable $createdAt,
        string $ip,
        string $actionType,
        ?Teacher $activeUser = null,
        ?Teacher $realUser = null,
        ?EducationalCentre $educationalCentre = null,
        ?AcademicYear $academicYear = null,
        ?string $route = null,
        ?string $method = null,
        ?int $statusCode = null,
        ?array $data = null,
    ) {
        $this->createdAt         = $createdAt;
        $this->ip                = $ip;
        $this->actionType        = $actionType;
        $this->activeUser        = $activeUser;
        $this->realUser          = $realUser;
        $this->educationalCentre = $educationalCentre;
        $this->academicYear      = $academicYear;
        $this->route             = $route;
        $this->method            = $method;
        $this->statusCode        = $statusCode;
        $this->data              = $data;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getActiveUser(): ?Teacher
    {
        return $this->activeUser;
    }

    public function getRealUser(): ?Teacher
    {
        return $this->realUser;
    }

    public function getEducationalCentre(): ?EducationalCentre
    {
        return $this->educationalCentre;
    }

    public function getAcademicYear(): ?AcademicYear
    {
        return $this->academicYear;
    }

    public function getActionType(): string
    {
        return $this->actionType;
    }

    public function getRoute(): ?string
    {
        return $this->route;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /** @return array<string, mixed>|null */
    public function getData(): ?array
    {
        return $this->data;
    }
}
