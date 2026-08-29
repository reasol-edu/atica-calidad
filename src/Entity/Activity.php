<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ActivityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A task teachers must complete, grouped under an ActivityCategory. When linked to a Folder (a
 * one-to-one, optional link), submissions are literally the Documents/DocumentRevisions of that
 * folder — the activity's own permissions are exactly the folder's (FolderVoter/
 * DocumentTreeAccessChecker), not a separate permission model. Without a folder, an activity is
 * just a reminder with a deadline and manual completion (see setAutoComplete()).
 */
#[ORM\Entity(repositoryClass: ActivityRepository::class)]
class Activity
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'activities')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ActivityCategory $category;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Deadline is a day/month pair, not a date — it repeats every academic year, with no year of its own. */
    #[ORM\Column]
    private int $startDay;

    #[ORM\Column]
    private int $startMonth;

    #[ORM\Column]
    private int $endDay;

    #[ORM\Column]
    private int $endMonth;

    /** One-to-one: a folder can back at most one activity. Submissions are that folder's documents. */
    #[ORM\OneToOne(inversedBy: 'activity')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL', unique: true)]
    private ?Folder $folder = null;

    /** Optional root whose leaf descendants (matched to a submission row's profile/subperfil via
     *  ListItem::getAssociatedProfile()/getAssociatedProfileListItem()) name each submission. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ListItem $listItem = null;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'activity_tag')]
    private Collection $tags;

    #[ORM\Column]
    private bool $required = true;

    #[ORM\Column]
    private bool $autoComplete = false;

    #[ORM\Column(enumType: ActivitySubmissionScope::class)]
    private ActivitySubmissionScope $submissionScope = ActivitySubmissionScope::ByProfile;

    #[ORM\Column]
    private int $position = 0;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCategory(): ActivityCategory
    {
        return $this->category;
    }

    public function setCategory(ActivityCategory $category): static
    {
        $this->category = $category;

        return $this;
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

    public function getStartDay(): int
    {
        return $this->startDay;
    }

    public function getStartMonth(): int
    {
        return $this->startMonth;
    }

    public function setStart(int $day, int $month): static
    {
        $this->startDay   = $day;
        $this->startMonth = $month;

        return $this;
    }

    public function getEndDay(): int
    {
        return $this->endDay;
    }

    public function getEndMonth(): int
    {
        return $this->endMonth;
    }

    public function setEnd(int $day, int $month): static
    {
        $this->endDay   = $day;
        $this->endMonth = $month;

        return $this;
    }

    public function getFolder(): ?Folder
    {
        return $this->folder;
    }

    /** Clearing the folder also turns off auto-complete — it's meaningless without documents to publish. */
    public function setFolder(?Folder $folder): static
    {
        if ($folder !== null && $folder->getEducationalCentre() !== $this->category->getEducationalCentre()) {
            throw new \LogicException('An activity cannot be linked to a folder from another centre.');
        }

        $this->folder = $folder;
        if ($folder === null) {
            $this->autoComplete = false;
        }

        return $this;
    }

    public function requiresSubmissions(): bool
    {
        return $this->folder !== null;
    }

    public function getListItem(): ?ListItem
    {
        return $this->listItem;
    }

    public function setListItem(?ListItem $listItem): static
    {
        $this->listItem = $listItem;

        return $this;
    }

    /** @return Collection<int, Tag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(Tag $tag): static
    {
        $this->tags->removeElement($tag);

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): static
    {
        $this->required = $required;

        return $this;
    }

    public function isAutoComplete(): bool
    {
        return $this->autoComplete;
    }

    /** Automatic completion needs documents to detect as published — never valid without a folder. */
    public function setAutoComplete(bool $autoComplete): static
    {
        if ($autoComplete && $this->folder === null) {
            throw new \LogicException('An activity without a folder can never be auto-completed.');
        }

        $this->autoComplete = $autoComplete;

        return $this;
    }

    public function getSubmissionScope(): ActivitySubmissionScope
    {
        return $this->submissionScope;
    }

    public function setSubmissionScope(ActivitySubmissionScope $submissionScope): static
    {
        $this->submissionScope = $submissionScope;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
