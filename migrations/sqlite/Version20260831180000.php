<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260831180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aviso de documento pendiente de revisar pasa a 3 modos (desactivado/individual/resumen diario); añade los mismos 3 modos para documento aceptado/rechazado; cola de eventos para el resumen diario (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql(<<<'SQL'
            UPDATE setting_definition
            SET key = 'notifications.pending_review_notification_mode',
                type = 'choice',
                default_value = 'daily_digest',
                choices = 'disabled,individual,daily_digest'
            WHERE key = 'notifications.pending_review_reminder_enabled'
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE global_setting_value
            SET value = CASE value WHEN 'true' THEN 'individual' ELSE 'disabled' END
            WHERE definition_id = (SELECT id FROM setting_definition WHERE key = 'notifications.pending_review_notification_mode')
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE centre_setting_value
            SET value = CASE value WHEN 'true' THEN 'individual' ELSE 'disabled' END
            WHERE definition_id = (SELECT id FROM setting_definition WHERE key = 'notifications.pending_review_notification_mode')
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE teacher_setting_value
            SET value = CASE value WHEN 'true' THEN 'individual' ELSE 'disabled' END
            WHERE definition_id = (SELECT id FROM setting_definition WHERE key = 'notifications.pending_review_notification_mode')
        SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                ('d80c95c9-54b3-4497-b6f1-9ee9c99f8908', 'notifications.document_accepted_notification_mode', 'choice', 'daily_digest', 1, 1, 1, NULL, NULL, 'disabled,individual,daily_digest', 'settings.category.email_alerts', 10, 70),
                ('e111e39a-5b4b-4516-84fe-aed1c0b6db57', 'notifications.document_rejected_notification_mode', 'choice', 'daily_digest', 1, 1, 1, NULL, NULL, 'disabled,individual,daily_digest', 'settings.category.email_alerts', 10, 80)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE document_review_notification_event (
                id BLOB NOT NULL,
                document_revision_id BLOB NOT NULL,
                kind VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT FK_B4D3F83E9F534BE5 FOREIGN KEY (document_revision_id) REFERENCES document_revision (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_B4D3F83E9F534BE5 ON document_review_notification_event (document_revision_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql('DROP TABLE document_review_notification_event');

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('notifications.document_accepted_notification_mode', 'notifications.document_rejected_notification_mode')");

        $this->addSql(<<<'SQL'
            UPDATE global_setting_value
            SET value = CASE value WHEN 'individual' THEN 'true' ELSE 'false' END
            WHERE definition_id = (SELECT id FROM setting_definition WHERE key = 'notifications.pending_review_notification_mode')
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE centre_setting_value
            SET value = CASE value WHEN 'individual' THEN 'true' ELSE 'false' END
            WHERE definition_id = (SELECT id FROM setting_definition WHERE key = 'notifications.pending_review_notification_mode')
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE teacher_setting_value
            SET value = CASE value WHEN 'individual' THEN 'true' ELSE 'false' END
            WHERE definition_id = (SELECT id FROM setting_definition WHERE key = 'notifications.pending_review_notification_mode')
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE setting_definition
            SET key = 'notifications.pending_review_reminder_enabled',
                type = 'boolean',
                default_value = 'true',
                choices = NULL
            WHERE key = 'notifications.pending_review_notification_mode'
        SQL);
    }
}
