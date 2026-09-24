<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Migration\ActivityCycleCorrection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Corrects the academic year Version20260923130000 gave to the submissions and completions that
 * already existed, for activities straddling the start of the academic year — see
 * ActivityCycleCorrection. A data fix: going down leaves them as they are.
 */
final class Version20260929090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Curso académico de las entregas y completados anteriores a Version20260923130000 en actividades que empiezan antes del inicio del curso (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');

        foreach (ActivityCycleCorrection::documents($this->connection) as $correction) {
            $this->addSql('UPDATE document SET activity_cycle_year = ? WHERE id = ?', [$correction['cycleYear'], $correction['id']], [ParameterType::INTEGER, ParameterType::BINARY]);
        }
        foreach (ActivityCycleCorrection::completions($this->connection) as $correction) {
            $this->addSql('UPDATE activity_completion SET cycle_year = ? WHERE id = ?', [$correction['cycleYear'], $correction['id']], [ParameterType::INTEGER, ParameterType::BINARY]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse en MySQL o MariaDB.');
    }
}
