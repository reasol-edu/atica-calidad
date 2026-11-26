<?php

declare(strict_types=1);

namespace App\Service\Form;

/**
 * Something a person did that the forms cannot go along with; `getMessage()` is a translation key of the domain "forms"
 * (under `error.`), `problems` what has to be fixed first when it is about the content.
 */
final class FormException extends \RuntimeException
{
    /**
     * @param list<array<string, mixed>> $problems
     * @param array<string, mixed>       $details  what the page needs to say more (`error.delivered_rows_changed`: the `count` of rows
     *                                             changed and the `cells` that blink)
     */
    public function __construct(string $key, public readonly array $problems = [], public readonly array $details = [])
    {
        parent::__construct($key);
    }
}
