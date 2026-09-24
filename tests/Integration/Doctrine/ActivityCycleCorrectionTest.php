<?php

declare(strict_types=1);

namespace App\Tests\Integration\Doctrine;

use App\Doctrine\Migration\ActivityCycleCorrection;
use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivityCompletion;
use App\Entity\ActivitySubmissionScope;
use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\RepositoryTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * What Version20260929090000 corrects: submissions and completions that Version20260923130000
 * (run here on Sep 23) stamped with the academic year of their date, for a Sep 10 – Sep 30
 * activity — which the application keys whole to the academic year it ends in.
 */
final class ActivityCycleCorrectionTest extends RepositoryTestCase
{
    private Connection $connection;
    private EducationalCentre $centre;
    private Folder $folder;
    private Activity $activity;
    private Teacher $ana;
    private Teacher $bea;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->em->getConnection();
        $this->connection->executeStatement('CREATE TABLE doctrine_migration_versions (version VARCHAR(191) NOT NULL PRIMARY KEY, executed_at DATETIME DEFAULT NULL, execution_time INTEGER DEFAULT NULL)');
        $this->connection->insert('doctrine_migration_versions', ['version' => ActivityCycleCorrection::STAMPING_MIGRATION, 'executed_at' => '2026-09-23 12:00:00']);

        $this->centre   = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $section        = (new DocumentSection())->setEducationalCentre($this->centre)->setName('Sección')->setPosition(0);
        $this->folder   = (new Folder())->setDocumentSection($section)->setName('Entregas')->setPosition(0);
        $category       = (new ActivityCategory())->setName('Inicio de curso')->setEducationalCentre($this->centre);
        $this->activity = (new Activity())->setCategory($category)->setTitle('Evaluación inicial')->setStart(10, 9)->setEnd(30, 9)
            ->setSubmissionScope(ActivitySubmissionScope::Individual)->setFolder($this->folder);
        $this->ana = (new Teacher(new PersonName('Ana', 'Ruiz')))->setUsername('ana');
        $this->bea = (new Teacher(new PersonName('Bea', 'Gil')))->setUsername('bea');

        $this->persist($this->centre, $section, $this->folder, $category, $this->activity, $this->ana, $this->bea);
    }

    private function submission(Teacher $teacher, string $uploadedAt, int $cycleYear, ?string $name = null): Document
    {
        $document = new Document($this->folder, $name ?? $teacher->getName()->getFirstName());
        $file     = new DocumentFile(hash('sha256', $uploadedAt . $teacher->getUsername()), 'x', 'text/plain', 'x.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, true, $teacher);
        $this->persist($document, $file, $revision);
        $this->connection->executeStatement(
            'UPDATE document SET uploaded_at = ?, activity_cycle_year = ? WHERE id = ?',
            [$uploadedAt, $cycleYear, $document->getId()->toBinary()],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::BINARY],
        );

        return $document;
    }

    private function completion(Teacher $teacher, string $completedAt, int $cycleYear): ActivityCompletion
    {
        $completion = new ActivityCompletion($this->activity, $teacher, null, null, $teacher, $cycleYear);
        $this->persist($completion);
        $this->connection->executeStatement(
            'UPDATE activity_completion SET completed_at = ? WHERE id = ?',
            [$completedAt, $completion->getId()->toBinary()],
            [ParameterType::STRING, ParameterType::BINARY],
        );

        return $completion;
    }

    /**
     * @param list<array{id: string, cycleYear: int}> $corrections
     *
     * @return array<string, int>
     */
    private static function byId(array $corrections): array
    {
        return array_column($corrections, 'cycleYear', 'id');
    }

    public function testWhatWasDeliveredInTheFirstDaysMovesToTheOccurrencesAcademicYear(): void
    {
        $early   = $this->submission($this->ana, '2026-09-12 10:00:00', 2025);
        $this->submission($this->ana, '2026-09-20 10:00:00', 2026, 'Ana (anexo)');
        // Uploaded after the stamping migration: the application stamped it, whatever it says.
        $this->submission($this->ana, '2026-09-24 10:00:00', 2025, 'Ana (otro)');
        // Bea delivered on the 12th and, not seeing it, again on the 20th: the first one stays.
        $this->submission($this->bea, '2026-09-12 10:00:00', 2025);
        $this->submission($this->bea, '2026-09-20 10:00:00', 2026);
        $completed = $this->completion($this->ana, '2026-09-12 10:00:00', 2025);

        self::assertSame([$early->getId()->toBinary() => 2026], self::byId(ActivityCycleCorrection::documents($this->connection)));
        self::assertSame([$completed->getId()->toBinary() => 2026], self::byId(ActivityCycleCorrection::completions($this->connection)));
    }

    /** With a centre whose academic year starts on Oct 1, Sep 10 – Sep 30 is last year's tail: nothing to correct. */
    public function testItUsesTheCentresOwnStartOfTheAcademicYear(): void
    {
        $definition = Uuid::v7()->toBinary();
        $this->connection->executeStatement(
            "INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, category, category_order, position) VALUES (?, 'academic_year.start_date', 'day_month', '09-15', 1, 1, 0, 'x', 0, 0)",
            [$definition],
            [ParameterType::BINARY],
        );
        $this->connection->executeStatement(
            "INSERT INTO centre_setting_value (id, value, locked, definition_id, centre_id) VALUES (?, '10-01', 0, ?, ?)",
            [Uuid::v7()->toBinary(), $definition, $this->centre->getId()->toBinary()],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY],
        );
        $this->submission($this->ana, '2026-09-12 10:00:00', 2025);

        self::assertSame([], ActivityCycleCorrection::documents($this->connection));
    }

    public function testNothingToCorrectWhereTheStampingMigrationNeverRan(): void
    {
        $this->connection->executeStatement('DELETE FROM doctrine_migration_versions');
        $this->submission($this->ana, '2026-09-12 10:00:00', 2025);

        self::assertSame([], ActivityCycleCorrection::documents($this->connection));
    }
}
