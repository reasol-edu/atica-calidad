<?php

declare(strict_types=1);

namespace App\Service\Form\Layout;

/** A paragraph: its runs, its alignment and, in the body of a document, which of its styles it takes (`label` is the caption of a block). */
final readonly class Paragraph
{
    public const string BODY  = 'body';
    public const string LABEL = 'label';

    /** @param list<Run> $runs */
    public function __construct(
        public array $runs,
        /** @var 'left'|'center'|'right'|'justify' */
        public string $align = 'left',
        public string $style = self::BODY,
    ) {}
}
