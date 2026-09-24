<?php

declare(strict_types=1);

namespace App\Doctrine\Migration;

use App\Model\DayMonth;
use App\Service\ActivityDeadlineChecker;
use Doctrine\DBAL\Connection;

/**
 * Version20260923130000 stamped the submissions (document.activity_cycle_year) and completions
 * (activity_completion.cycle_year) that already existed with the academic year of their date,
 * cut on Sep 15. The application keys them by the occurrence of the activity instead
 * (ActivityDeadlineChecker::cycleOf()): an occurrence straddling the start of the academic year
 * (Sep 10 – Sep 30) belongs whole to the academic year it ends in, so whatever was delivered or
 * completed in its first days got last year's key and vanished from "Mis entregas".
 *
 * This works out, for the rows that migration stamped (older than when it ran), the key the
 * application would have given them — with each centre's own start of the academic year — and
 * returns the ones that differ. A row is left alone when its occurrence already has an
 * equivalent one (someone delivered or completed it again after updating), not to duplicate it.
 * Shared by the three platforms' Version20260929090000.
 */
final class ActivityCycleCorrection
{
    public const string STAMPING_MIGRATION = 'DoctrineMigrations\Version20260923130000';

    /** @return list<array{id: string, cycleYear: int}> document ids and the cycle key each should have */
    public static function documents(Connection $connection): array
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT d.id, d.uploaded_at AS at, d.activity_cycle_year AS cycle_year, d.folder_id, d.name, d.upload_profile_id, d.upload_list_item_id,'
            . ' r.uploaded_by_id AS first_uploader, a.start_month, a.start_day, a.end_month, a.end_day, c.educational_centre_id AS centre_id'
            . ' FROM document d'
            . ' JOIN activity a ON a.folder_id = d.folder_id'
            . ' JOIN activity_category c ON c.id = a.category_id'
            . ' LEFT JOIN document_revision r ON r.document_id = d.id AND r.version = 1',
        );

        return self::corrections($connection, $rows, ['folder_id', 'name', 'upload_profile_id', 'upload_list_item_id', 'first_uploader']);
    }

    /** @return list<array{id: string, cycleYear: int}> completion ids and the cycle key each should have */
    public static function completions(Connection $connection): array
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT x.id, x.completed_at AS at, x.cycle_year, x.activity_id, x.teacher_id, x.profile_id, x.list_item_id,'
            . ' a.start_month, a.start_day, a.end_month, a.end_day, c.educational_centre_id AS centre_id'
            . ' FROM activity_completion x'
            . ' JOIN activity a ON a.id = x.activity_id'
            . ' JOIN activity_category c ON c.id = a.category_id',
        );

        return self::corrections($connection, $rows, ['activity_id', 'teacher_id', 'profile_id', 'list_item_id']);
    }

    /**
     * @param list<array<string, mixed>> $rows     each with id, at, cycle_year, the activity's dates, centre_id and the $identity columns
     * @param list<string>               $identity columns that, with the cycle key, make two rows the same submission or completion
     *
     * @return list<array{id: string, cycleYear: int}>
     */
    private static function corrections(Connection $connection, array $rows, array $identity): array
    {
        $stampedAt = $connection->fetchOne('SELECT executed_at FROM doctrine_migration_versions WHERE version = ?', [self::STAMPING_MIGRATION]);
        if (!\is_string($stampedAt) || $stampedAt === '') {
            return [];
        }
        $stampedAt = new \DateTimeImmutable($stampedAt);
        $starts    = self::academicYearStarts($connection);

        $taken = [];
        foreach ($rows as $row) {
            $current = self::number($row['cycle_year']);
            if ($current !== null) {
                $taken[self::identity($row, $identity, $current)] = true;
            }
        }

        $corrections = [];
        foreach ($rows as $row) {
            $at = new \DateTimeImmutable(self::raw($row['at']));
            if ($at >= $stampedAt) {
                continue;
            }
            $start = $starts['centres'][self::raw($row['centre_id'])] ?? $starts['global'];
            $key   = ActivityDeadlineChecker::cycleOf(
                self::number($row['start_month']) ?? 1,
                self::number($row['start_day']) ?? 1,
                self::number($row['end_month']) ?? 1,
                self::number($row['end_day']) ?? 1,
                $start,
                $at,
            )[2];
            if (self::number($row['cycle_year']) === $key) {
                continue;
            }
            $target = self::identity($row, $identity, $key);
            if (isset($taken[$target])) {
                continue;
            }
            $taken[$target] = true;
            $corrections[]  = ['id' => self::raw($row['id']), 'cycleYear' => $key];
        }

        return $corrections;
    }

    /**
     * Each centre's start of the academic year, resolved as AppSettings::getForCentre() does:
     * a locked global value, else the centre's, else the global one, else the default.
     *
     * @return array{global: DayMonth, centres: array<string, DayMonth>}
     */
    private static function academicYearStarts(Connection $connection): array
    {
        $key        = $connection->getDatabasePlatform()->quoteSingleIdentifier('key');
        $definition = $connection->fetchAssociative("SELECT id, default_value FROM setting_definition WHERE {$key} = ?", [ActivityDeadlineChecker::START_DATE_SETTING]);
        $fallback   = DayMonth::tryParse(ActivityDeadlineChecker::DEFAULT_START_DATE) ?? throw new \LogicException('Invalid default academic year start date.');
        if ($definition === false) {
            return ['global' => $fallback, 'centres' => []];
        }
        $default = DayMonth::tryParse($definition['default_value']) ?? $fallback;

        $global = $connection->fetchAssociative(
            'SELECT g.value, g.locked FROM global_setting_value g JOIN setting_definition s ON s.id = g.definition_id WHERE s.' . $key . ' = ?',
            [ActivityDeadlineChecker::START_DATE_SETTING],
        );
        $globalStart = $global === false ? $default : (DayMonth::tryParse($global['value']) ?? $default);
        if ($global !== false && \in_array($global['locked'], [true, 1, '1', 't', 'true'], true)) {
            return ['global' => $globalStart, 'centres' => []];
        }

        $centres = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT c.centre_id, c.value FROM centre_setting_value c JOIN setting_definition s ON s.id = c.definition_id WHERE s.' . $key . ' = ?',
            [ActivityDeadlineChecker::START_DATE_SETTING],
        ) as $row) {
            $centres[self::raw($row['centre_id'])] = DayMonth::tryParse($row['value']) ?? $globalStart;
        }

        return ['global' => $globalStart, 'centres' => $centres];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $columns
     */
    private static function identity(array $row, array $columns, int $cycleYear): string
    {
        $parts = [(string) $cycleYear];
        foreach ($columns as $column) {
            $parts[] = bin2hex(self::raw($row[$column]));
        }

        return implode('|', $parts);
    }

    /** An integer column's value, whatever type the platform returns it as; null when it has none. */
    private static function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /** A column's value as a string key, whatever the platform returns (uuid string, binary string, stream). */
    private static function raw(mixed $value): string
    {
        if (\is_resource($value)) {
            $value = stream_get_contents($value);
        }

        return \is_scalar($value) ? (string) $value : '';
    }
}
