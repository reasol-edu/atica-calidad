<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Activity;

/** One activity of a bulk edit's preview: what the setting reads now and what it would read after. */
final readonly class ActivityBulkEditRow
{
    public function __construct(
        public Activity $activity,
        public string $from,
        public string $to,
    ) {}

    public function changes(): bool
    {
        return $this->from !== $this->to;
    }
}
