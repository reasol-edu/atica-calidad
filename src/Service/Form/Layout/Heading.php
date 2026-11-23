<?php

declare(strict_types=1);

namespace App\Service\Form\Layout;

/** A heading of the body: level 0 is the title of the document, 1 to 5 the sections. */
final readonly class Heading
{
    public function __construct(
        public int $level,
        public string $text,
    ) {}
}
