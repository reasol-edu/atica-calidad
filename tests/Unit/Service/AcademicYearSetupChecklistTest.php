<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Repository\AcademicYearRepository;
use App\Repository\NonWorkingDayRepository;
use App\Repository\SpecificProfileAssignmentRepository;
use App\Service\AcademicYearSetupChecklist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class AcademicYearSetupChecklistTest extends TestCase
{
    /** @param list<string> $names */
    private function checklist(array $names): AcademicYearSetupChecklist
    {
        $centre = new EducationalCentre();
        $years  = $this->createStub(AcademicYearRepository::class);
        $years->method('findByCentreOrderedByName')->willReturn(array_map(
            static fn (string $name): AcademicYear => (new AcademicYear())->setName($name)->setEducationalCentre($centre),
            $names,
        ));

        return new AcademicYearSetupChecklist(
            $years,
            $this->createStub(NonWorkingDayRepository::class),
            $this->createStub(SpecificProfileAssignmentRepository::class),
            new MockClock('2026-06-20'),
        );
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function names(): iterable
    {
        yield 'none yet: this calendar year and the next' => [[], '2026-2027'];
        yield 'long form, latest by natural order'         => [['2025-2026', '2024-2025'], '2026-2027'];
        yield 'short second year'                          => [['2025/26'], '2026/27'];
        yield 'short second year across a century'        => [['2099-00'], '2100-01'];
        yield 'free-form name: falls back to the calendar' => [['Curso actual'], '2026-2027'];
    }

    /** @param list<string> $existing */
    #[DataProvider('names')]
    public function testSuggestsTheNextName(array $existing, string $expected): void
    {
        self::assertSame($expected, $this->checklist($existing)->suggestedName(new EducationalCentre()));
    }
}
