<?php

declare(strict_types=1);

namespace App\Service\Form\Layout;

/**
 * A piece of text of a header or footer with one formatting, or one of the things that are not text: a line break or a page field
 * (`page` is the number of the page, `pages` the total).
 */
final readonly class Run
{
    public const string TEXT  = 'text';
    public const string BREAK = 'break';
    public const string PAGE  = 'page';
    public const string PAGES = 'pages';
    /** The centre's logo ({logo}) and its education authority's ({logo2}): images, drawn where they are. */
    public const string LOGO  = 'logo';
    public const string LOGO2 = 'logo2';

    public function __construct(
        public string $kind,
        public string $text = '',
        public bool $bold = false,
        public bool $italic = false,
        public bool $underline = false,
        /** @var ?string a hex colour without '#' (e.g. "46603d") */
        public ?string $color = null,
        /** @var ?float the size in points; null is the document's */
        public ?float $size = null,
    ) {}

    public function isField(): bool
    {
        return $this->kind === self::PAGE || $this->kind === self::PAGES;
    }

    public function isImage(): bool
    {
        return $this->kind === self::LOGO || $this->kind === self::LOGO2;
    }
}
