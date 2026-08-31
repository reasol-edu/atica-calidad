<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260831180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aviso de documento pendiente de revisar pasa a 3 modos (desactivado/individual/resumen diario); añade los mismos 3 modos para documento aceptado/rechazado; cola de eventos para el resumen diario (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            UPDATE setting_definition
            SET `key` = 'notifications.pending_review_notification_mode',
                type = 'choice',
                default_value = 'daily_digest',
                choices = 'disabled,individual,daily_digest'
            WHERE `key` = 'notifications.pending_review_reminder_enabled'
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE global_setting_value
            SET value = IF(value = 'true', 'individual', 'disabled')
            WHERE definition_id = (SELECT id FROM setting_definition WHERE `key` = 'notifications.pending_review_notification_mode')
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE centre_setting_value
            SET value = IF(value = 'true', 'individual', 'disabled')
            WHERE definition_id = (SELECT id FROM setting_definition WHERE `key` = 'notifications.pending_review_notification_mode')
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE teacher_setting_value
            SET value = IF(value = 'true', 'individual', 'disabled')
            WHERE definition_id = (SELECT id FROM setting_definition WHERE `key` = 'notifications.pending_review_notification_mode')
        SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'notifications.document_accepted_notification_mode', 'choice', 'daily_digest', 1, 1, 1, NULL, NULL, 'disabled,individual,daily_digest', 'settings.category.email_alerts', 10, 70),
                (UNHEX(REPLACE(UUID(), '-', '')), 'notifications.document_rejected_notification_mode', 'choice', 'daily_digest', 1, 1, 1, NULL, NULL, 'disabled,individual,daily_digest', 'settings.category.email_alerts', 10, 80)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE document_review_notification_event (
                id BINARY(16) NOT NULL,
                document_revision_id BINARY(16) NOT NULL,
                kind VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX IDX_B4D3F83E9F534BE5 (document_revision_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql('ALTER TABLE document_review_notification_event ADD CONSTRAINT FK_B4D3F83E9F534BE5 FOREIGN KEY (document_revision_id) REFERENCES document_revision (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('ALTER TABLE document_review_notification_event DROP FOREIGN KEY FK_B4D3F83E9F534BE5');
        $this->addSql('DROP TABLE document_review_notification_event');

        $this->addSql("DELETE FROM setting_definition WHERE `key` IN ('notifications.document_accepted_notification_mode', 'notifications.document_rejected_notification_mode')");

        $this->addSql(<<<'SQL'
            UPDATE global_setting_value
            SET value = IF(value = 'individual', 'true', 'false')
            WHERE definition_id = (SELECT id FROM setting_definition WHERE `key` = 'notifications.pending_review_notification_mode')
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE centre_setting_value
            SET value = IF(value = 'individual', 'true', 'false')
            WHERE definition_id = (SELECT id FROM setting_definition WHERE `key` = 'notifications.pending_review_notification_mode')
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE teacher_setting_value
            SET value = IF(value = 'individual', 'true', 'false')
            WHERE definition_id = (SELECT id FROM setting_definition WHERE `key` = 'notifications.pending_review_notification_mode')
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE setting_definition
            SET `key` = 'notifications.pending_review_reminder_enabled',
                type = 'boolean',
                default_value = 'true',
                choices = NULL
            WHERE `key` = 'notifications.pending_review_notification_mode'
        SQL);
    }
}
