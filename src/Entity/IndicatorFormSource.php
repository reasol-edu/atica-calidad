<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndicatorFormSourceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Where a computed indicator takes its value from: a field (or table column) of the documents delivered in an activity filled in
 * online. The value of a period is an aggregation of that field over the latest approved delivery of each slot, optionally divided
 * by the sum of another field (a ratio, shown as a percentage), and optionally broken down by group.
 */
#[ORM\Entity(repositoryClass: IndicatorFormSourceRepository::class)]
class IndicatorFormSource
{
    public const string SUM = 'sum';
    public const string AVG = 'avg';
    public const string MIN = 'min';
    public const string MAX = 'max';
    public const string COUNT = 'count';
    public const string COUNT_IF = 'count_if';
    public const string PERCENT_IF = 'percent_if';

    /** @var list<string> */
    public const array AGGREGATIONS = [self::SUM, self::AVG, self::MIN, self::MAX, self::COUNT, self::COUNT_IF, self::PERCENT_IF];

    /** @var list<string> */
    public const array OPERATORS = ['eq', 'ne', 'gt', 'ge', 'lt', 'le'];

    /** No breakdown, one group per delivery slot (profile, list item, teacher), or the options of a choice field («field:KEY»). */
    public const string GROUP_NONE = '';
    public const string GROUP_SLOT = 'slot';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Indicator $indicator;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Activity $activity;

    #[ORM\Column(length: 36)]
    private string $fieldKey;

    #[ORM\Column(length: 12)]
    private string $aggregation = self::SUM;

    /** For count_if / percent_if: the comparison with $conditionValue. */
    #[ORM\Column(length: 2, nullable: true)]
    private ?string $conditionOp = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $conditionValue = null;

    /** The field whose sum divides this one's (a ratio × 100), or null. */
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $denominatorKey = null;

    #[ORM\Column(length: 60, options: ['default' => ''])]
    private string $groupBy = self::GROUP_NONE;

    public function __construct(Indicator $indicator, Activity $activity, string $fieldKey)
    {
        $this->indicator = $indicator;
        $this->activity  = $activity;
        $this->fieldKey  = $fieldKey;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getIndicator(): Indicator
    {
        return $this->indicator;
    }

    public function getActivity(): Activity
    {
        return $this->activity;
    }

    public function getFieldKey(): string
    {
        return $this->fieldKey;
    }

    public function getAggregation(): string
    {
        return $this->aggregation;
    }

    public function getConditionOp(): ?string
    {
        return $this->conditionOp;
    }

    public function getConditionValue(): ?string
    {
        return $this->conditionValue;
    }

    public function getDenominatorKey(): ?string
    {
        return $this->denominatorKey;
    }

    public function getGroupBy(): string
    {
        return $this->groupBy;
    }

    public function configure(Activity $activity, string $fieldKey, string $aggregation, ?string $conditionOp, ?string $conditionValue, ?string $denominatorKey, string $groupBy): static
    {
        $this->activity       = $activity;
        $this->fieldKey       = $fieldKey;
        $this->aggregation    = in_array($aggregation, self::AGGREGATIONS, true) ? $aggregation : self::SUM;
        $needsCondition       = in_array($this->aggregation, [self::COUNT_IF, self::PERCENT_IF], true);
        $this->conditionOp    = $needsCondition && in_array($conditionOp, self::OPERATORS, true) ? $conditionOp : ($needsCondition ? 'eq' : null);
        $this->conditionValue = $needsCondition ? mb_substr((string) $conditionValue, 0, 100) : null;
        $this->denominatorKey = $denominatorKey === '' ? null : $denominatorKey;
        $this->groupBy        = $groupBy;

        return $this;
    }
}
