<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Form;

use App\Model\ActivitySubmissionSlot;
use App\Service\Form\FormDefinitionNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/** «Quién decide» of a step of the approval chain in the definition, and the element read back from a slot key (fase 0c). */
final class FormApprovalScopeTest extends TestCase
{
    public function testAStepIsDecidedByAnyoneWithTheProfileUnlessLimitedToTheElement(): void
    {
        $definitions = new FormDefinitionNormalizer(new HtmlSanitizer(new HtmlSanitizerConfig()));
        $profile     = '0193c7a2-7c1d-7000-8000-000000000001';

        $steps = $definitions->normalize(['sections' => [], 'approval' => [
            ['name' => 'Sin decir', 'profile' => $profile],
            ['name' => 'Limitado', 'profile' => $profile, 'scope' => 'element'],
            ['name' => 'Otro valor', 'profile' => $profile, 'scope' => 'todos'],
        ]])['approval'];

        self::assertSame(['any', 'element', 'any'], array_column($steps, 'scope'));
    }

    public function testTheElementOfASlotIsTheThirdPartOfItsKey(): void
    {
        $profile = '0193c7a2-7c1d-7000-8000-000000000001';
        $sub     = '0193c7a2-7c1d-7000-8000-000000000002';
        $leaf    = '0193c7a2-7c1d-7000-8000-000000000003';

        self::assertSame($leaf, ActivitySubmissionSlot::leafOf("{$profile}:{$sub}:{$leaf}:"));
        self::assertNull(ActivitySubmissionSlot::leafOf("{$profile}:::"), 'a slot with no element');
        self::assertNull(ActivitySubmissionSlot::leafOf('not-a-key'));
        self::assertNull(ActivitySubmissionSlot::leafOf("{$profile}:{$sub}:nope:"));
    }
}
