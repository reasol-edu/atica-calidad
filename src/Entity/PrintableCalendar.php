<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PrintableCalendarRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * A calendar a teacher generates as a PDF (Utilidades › Generador de calendarios): a title, an
 * optional description, an optional explicit date range (computed from its periods and dates when
 * left blank — see PrintableCalendarPdfBuilder), and the two colours the render falls back to for
 * non-working days and weekends when not overridden here. Personal to its owner: nobody else can
 * see or edit it (PrintableCalendarRepository::findByOwnerAndId always filters by owner).
 */
#[ORM\Entity(repositoryClass: PrintableCalendarRepository::class)]
class PrintableCalendar
{
    /** Fallback for nonWorkingDayColor when the teacher hasn't overridden it — a soft red. */
    public const string DEFAULT_NON_WORKING_DAY_COLOR = '#fecaca';

    /** Fallback for weekendColor when the teacher hasn't overridden it — a neutral grey. */
    public const string DEFAULT_WEEKEND_COLOR = '#e5e7eb';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EducationalCentre $educationalCentre;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Teacher $owner;

    #[ORM\Column(length: 255)]
    private string $title;

    /**
     * Optional HTML shown between the page header and the calendar itself (nothing, when blank).
     * Stored raw (edited via the Quill rich-text editor, same as Folder::$description); sanitize
     * with sanitize_html('app.rich_text') at every render site, never on write.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $nonWorkingDayColor = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $weekendColor = null;

    #[ORM\Column(enumType: PrintableCalendarOrientation::class, options: ['default' => 'portrait'])]
    private PrintableCalendarOrientation $orientation = PrintableCalendarOrientation::Portrait;

    /** Percentage (50-200) applied to the orientation's default font sizes — see PrintableCalendarPdfBuilder. */
    #[ORM\Column(options: ['default' => 100])]
    private int $fontSizeScale = 100;

    /** Whether the PDF's running page header (title, centre) is shown, or skipped for more room. */
    #[ORM\Column(options: ['default' => true])]
    private bool $showHeader = true;

    /** Whether the PDF's running page footer (generated-on, page number) is shown, or skipped for more room. */
    #[ORM\Column(options: ['default' => true])]
    private bool $showFooter = true;

    /** Whether each day cell shows its assigned hours (e.g. "8h"), or just the day number. */
    #[ORM\Column(options: ['default' => true])]
    private bool $showHours = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, PrintableCalendarPeriod> */
    #[ORM\OneToMany(targetEntity: PrintableCalendarPeriod::class, mappedBy: 'calendar', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $periods;

    /** @var Collection<int, PrintableCalendarDate> */
    #[ORM\OneToMany(targetEntity: PrintableCalendarDate::class, mappedBy: 'calendar', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['date' => 'ASC'])]
    private Collection $dates;

    public function __construct(EducationalCentre $centre, AcademicYear $year, Teacher $owner, string $title)
    {
        $this->educationalCentre = $centre;
        $this->academicYear      = $year;
        $this->owner             = $owner;
        $this->title             = $title;
        $this->createdAt         = now();
        $this->periods           = new ArrayCollection();
        $this->dates             = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEducationalCentre(): EducationalCentre
    {
        return $this->educationalCentre;
    }

    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
    }

    public function setAcademicYear(AcademicYear $academicYear): static
    {
        $this->academicYear = $academicYear;

        return $this;
    }

    public function getOwner(): Teacher
    {
        return $this->owner;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getNonWorkingDayColor(): ?string
    {
        return $this->nonWorkingDayColor;
    }

    public function setNonWorkingDayColor(?string $color): static
    {
        $this->nonWorkingDayColor = $color;

        return $this;
    }

    public function getWeekendColor(): ?string
    {
        return $this->weekendColor;
    }

    public function setWeekendColor(?string $color): static
    {
        $this->weekendColor = $color;

        return $this;
    }

    public function getOrientation(): PrintableCalendarOrientation
    {
        return $this->orientation;
    }

    public function setOrientation(PrintableCalendarOrientation $orientation): static
    {
        $this->orientation = $orientation;

        return $this;
    }

    public function getFontSizeScale(): int
    {
        return $this->fontSizeScale;
    }

    public function setFontSizeScale(int $fontSizeScale): static
    {
        $this->fontSizeScale = $fontSizeScale;

        return $this;
    }

    public function isShowHeader(): bool
    {
        return $this->showHeader;
    }

    public function setShowHeader(bool $showHeader): static
    {
        $this->showHeader = $showHeader;

        return $this;
    }

    public function isShowFooter(): bool
    {
        return $this->showFooter;
    }

    public function setShowFooter(bool $showFooter): static
    {
        $this->showFooter = $showFooter;

        return $this;
    }

    public function isShowHours(): bool
    {
        return $this->showHours;
    }

    public function setShowHours(bool $showHours): static
    {
        $this->showHours = $showHours;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, PrintableCalendarPeriod> */
    public function getPeriods(): Collection
    {
        return $this->periods;
    }

    /** @return Collection<int, PrintableCalendarDate> */
    public function getDates(): Collection
    {
        return $this->dates;
    }

    public function addPeriod(
        string $description,
        string $color,
        bool $showJourneySummary,
        PrintableCalendarPeriodMode $mode,
        ?\DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        ?float $totalHours,
        ?float $mondayHours,
        ?float $tuesdayHours,
        ?float $wednesdayHours,
        ?float $thursdayHours,
        ?float $fridayHours,
    ): PrintableCalendarPeriod {
        $period = new PrintableCalendarPeriod(
            $this,
            $description,
            $color,
            $showJourneySummary,
            $mode,
            $startDate,
            $endDate,
            $totalHours,
            $mondayHours,
            $tuesdayHours,
            $wednesdayHours,
            $thursdayHours,
            $fridayHours,
            $this->periods->count(),
        );
        $this->periods->add($period);

        return $period;
    }

    public function removePeriod(PrintableCalendarPeriod $period): void
    {
        $this->periods->removeElement($period);
    }

    public function addDate(\DateTimeImmutable $date, string $color, string $description): PrintableCalendarDate
    {
        $entry = new PrintableCalendarDate($this, $date, $color, $description);
        $this->dates->add($entry);

        return $entry;
    }

    public function removeDate(PrintableCalendarDate $date): void
    {
        $this->dates->removeElement($date);
    }
}
