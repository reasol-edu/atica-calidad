<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260826133453 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE academic_year (id BINARY(16) NOT NULL, name VARCHAR(50) NOT NULL, educational_centre_id BINARY(16) NOT NULL, INDEX IDX_275AE72161F9EE23 (educational_centre_id), UNIQUE INDEX uq_academic_year_centre (name, educational_centre_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE teacher_academic_year (academic_year_id BINARY(16) NOT NULL, teacher_id BINARY(16) NOT NULL, INDEX IDX_EF1B6955C54F3401 (academic_year_id), INDEX IDX_EF1B695541807E1D (teacher_id), PRIMARY KEY (academic_year_id, teacher_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE centre_setting_value (id BINARY(16) NOT NULL, value LONGTEXT NOT NULL, locked TINYINT NOT NULL, definition_id BINARY(16) NOT NULL, centre_id BINARY(16) NOT NULL, file_id BINARY(16) DEFAULT NULL, INDEX IDX_306FFD17D11EA911 (definition_id), INDEX IDX_306FFD17463CD7C3 (centre_id), INDEX IDX_306FFD1793CB796C (file_id), UNIQUE INDEX uq_centre_setting_def_centre (definition_id, centre_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE educational_centre (id BINARY(16) NOT NULL, code VARCHAR(8) NOT NULL, name VARCHAR(255) NOT NULL, city VARCHAR(255) NOT NULL, active_academic_year_id BINARY(16) DEFAULT NULL, UNIQUE INDEX UNIQ_2E7EDDDC77153098 (code), INDEX IDX_2E7EDDDC3B9B1771 (active_academic_year_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE educational_centre_admins (educational_centre_id BINARY(16) NOT NULL, teacher_id BINARY(16) NOT NULL, INDEX IDX_9F1F12EF61F9EE23 (educational_centre_id), INDEX IDX_9F1F12EF41807E1D (teacher_id), PRIMARY KEY (educational_centre_id, teacher_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE educational_centre_quality_managers (educational_centre_id BINARY(16) NOT NULL, teacher_id BINARY(16) NOT NULL, INDEX IDX_155CABE61F9EE23 (educational_centre_id), INDEX IDX_155CABE41807E1D (teacher_id), PRIMARY KEY (educational_centre_id, teacher_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE educational_centre_internal_auditors (educational_centre_id BINARY(16) NOT NULL, teacher_id BINARY(16) NOT NULL, INDEX IDX_EE6B277561F9EE23 (educational_centre_id), INDEX IDX_EE6B277541807E1D (teacher_id), PRIMARY KEY (educational_centre_id, teacher_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE specific_profile (id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, position INT NOT NULL, active TINYINT NOT NULL, list_item_id BINARY(16) DEFAULT NULL, educational_centre_id BINARY(16) NOT NULL, INDEX IDX_72B5CDE4CE208F53 (list_item_id), INDEX IDX_72B5CDE461F9EE23 (educational_centre_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE specific_profile_assignment (id BINARY(16) NOT NULL, specific_profile_id BINARY(16) NOT NULL, list_item_id BINARY(16) DEFAULT NULL, teacher_id BINARY(16) NOT NULL, INDEX IDX_A5A5F2C9DF5533E (specific_profile_id), INDEX IDX_A5A5F2C9CE208F53 (list_item_id), INDEX IDX_A5A5F2C941807E1D (teacher_id), UNIQUE INDEX uq_specific_profile_assignment (specific_profile_id, list_item_id, teacher_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE list_item (id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, position INT NOT NULL, active TINYINT NOT NULL, parent_id BINARY(16) DEFAULT NULL, educational_centre_id BINARY(16) NOT NULL, INDEX IDX_5AD5FAF7727ACA70 (parent_id), INDEX IDX_5AD5FAF761F9EE23 (educational_centre_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE list_item_tag (list_item_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_9AF1FFD1CE208F53 (list_item_id), INDEX IDX_9AF1FFD1BAD26311 (tag_id), PRIMARY KEY (list_item_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tag (id BINARY(16) NOT NULL, name VARCHAR(100) NOT NULL, educational_centre_id BINARY(16) NOT NULL, INDEX IDX_389B78361F9EE23 (educational_centre_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE document_section (id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, position INT NOT NULL, parent_id BINARY(16) DEFAULT NULL, educational_centre_id BINARY(16) NOT NULL, INDEX IDX_891CDC33727ACA70 (parent_id), INDEX IDX_891CDC3361F9EE23 (educational_centre_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE document_section_profile (id BINARY(16) NOT NULL, document_section_id BINARY(16) NOT NULL, specific_profile_id BINARY(16) NOT NULL, list_item_id BINARY(16) DEFAULT NULL, INDEX IDX_5ADC66DE79E0482C (document_section_id), INDEX IDX_5ADC66DEDF5533E (specific_profile_id), INDEX IDX_5ADC66DECE208F53 (list_item_id), UNIQUE INDEX uq_document_section_profile (document_section_id, specific_profile_id, list_item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE email_notification_log (id BINARY(16) NOT NULL, recipient_name VARCHAR(200) NOT NULL, event_key VARCHAR(50) NOT NULL, subject VARCHAR(255) NOT NULL, success TINYINT NOT NULL, error_message LONGTEXT DEFAULT NULL, sent_at DATETIME NOT NULL, educational_centre_id BINARY(16) NOT NULL, recipient_id BINARY(16) DEFAULT NULL, INDEX IDX_E8B54561F9EE23 (educational_centre_id), INDEX idx_enl_centre_sent (educational_centre_id, sent_at), INDEX idx_enl_recipient (recipient_id), INDEX idx_enl_event (event_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE global_setting_value (id BINARY(16) NOT NULL, value LONGTEXT NOT NULL, locked TINYINT NOT NULL, definition_id BINARY(16) NOT NULL, file_id BINARY(16) DEFAULT NULL, INDEX IDX_466B8E0493CB796C (file_id), UNIQUE INDEX uq_global_setting_definition (definition_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE non_working_day (id BINARY(16) NOT NULL, date DATE NOT NULL, description VARCHAR(255) DEFAULT NULL, academic_year_id BINARY(16) NOT NULL, INDEX IDX_32411A39C54F3401 (academic_year_id), UNIQUE INDEX uq_non_working_day_year_date (academic_year_id, date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE school_event (id BINARY(16) NOT NULL, date DATE NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, url VARCHAR(500) DEFAULT NULL, general TINYINT NOT NULL, academic_year_id BINARY(16) NOT NULL, INDEX IDX_E554BCBDC54F3401 (academic_year_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE school_event_profile (id BINARY(16) NOT NULL, school_event_id BINARY(16) NOT NULL, specific_profile_id BINARY(16) NOT NULL, list_item_id BINARY(16) DEFAULT NULL, INDEX IDX_2812AF9E8FB1DCCF (school_event_id), INDEX IDX_2812AF9EDF5533E (specific_profile_id), INDEX IDX_2812AF9ECE208F53 (list_item_id), UNIQUE INDEX uq_school_event_profile (school_event_id, specific_profile_id, list_item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE setting_definition (id BINARY(16) NOT NULL, `key` VARCHAR(100) NOT NULL, type VARCHAR(255) NOT NULL, default_value LONGTEXT NOT NULL, global_scope TINYINT NOT NULL, centre_scope TINYINT NOT NULL, teacher_scope TINYINT NOT NULL, min_value INT DEFAULT NULL, max_value INT DEFAULT NULL, choices VARCHAR(500) DEFAULT NULL, category VARCHAR(100) NOT NULL, category_order INT NOT NULL, position INT NOT NULL, UNIQUE INDEX uq_setting_definition_key (`key`), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE setting_file (id BINARY(16) NOT NULL, hash VARCHAR(64) NOT NULL, content LONGBLOB NOT NULL, mime_type VARCHAR(100) NOT NULL, size INT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uq_setting_file_hash (hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE teacher (id BINARY(16) NOT NULL, username VARCHAR(180) NOT NULL, `admin` TINYINT NOT NULL, password VARCHAR(255) DEFAULT NULL, external TINYINT NOT NULL, active TINYINT NOT NULL, force_password_change TINYINT NOT NULL, email VARCHAR(180) DEFAULT NULL, pending_email VARCHAR(180) DEFAULT NULL, email_verification_token VARCHAR(64) DEFAULT NULL, email_verification_token_expires_at DATETIME DEFAULT NULL, password_reset_token VARCHAR(64) DEFAULT NULL, password_reset_token_expires_at DATETIME DEFAULT NULL, name_first_name VARCHAR(255) NOT NULL, name_last_name VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_B0F6A6D5F85E0677 (username), UNIQUE INDEX UNIQ_B0F6A6D5C4995C67 (email_verification_token), UNIQUE INDEX UNIQ_B0F6A6D56B7BA4B6 (password_reset_token), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE teacher_setting_value (id BINARY(16) NOT NULL, value VARCHAR(255) NOT NULL, definition_id BINARY(16) NOT NULL, teacher_id BINARY(16) NOT NULL, file_id BINARY(16) DEFAULT NULL, INDEX IDX_9C9E2521D11EA911 (definition_id), INDEX IDX_9C9E252141807E1D (teacher_id), INDEX IDX_9C9E252193CB796C (file_id), UNIQUE INDEX uq_teacher_setting_def_teacher (definition_id, teacher_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE academic_year ADD CONSTRAINT FK_275AE72161F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id)');
        $this->addSql('ALTER TABLE teacher_academic_year ADD CONSTRAINT FK_EF1B6955C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE teacher_academic_year ADD CONSTRAINT FK_EF1B695541807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE centre_setting_value ADD CONSTRAINT FK_306FFD17D11EA911 FOREIGN KEY (definition_id) REFERENCES setting_definition (id)');
        $this->addSql('ALTER TABLE centre_setting_value ADD CONSTRAINT FK_306FFD17463CD7C3 FOREIGN KEY (centre_id) REFERENCES educational_centre (id)');
        $this->addSql('ALTER TABLE centre_setting_value ADD CONSTRAINT FK_306FFD1793CB796C FOREIGN KEY (file_id) REFERENCES setting_file (id)');
        $this->addSql('ALTER TABLE educational_centre ADD CONSTRAINT FK_2E7EDDDC3B9B1771 FOREIGN KEY (active_academic_year_id) REFERENCES academic_year (id)');
        $this->addSql('ALTER TABLE educational_centre_admins ADD CONSTRAINT FK_9F1F12EF61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educational_centre_admins ADD CONSTRAINT FK_9F1F12EF41807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educational_centre_quality_managers ADD CONSTRAINT FK_155CABE61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educational_centre_quality_managers ADD CONSTRAINT FK_155CABE41807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educational_centre_internal_auditors ADD CONSTRAINT FK_EE6B277561F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educational_centre_internal_auditors ADD CONSTRAINT FK_EE6B277541807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE specific_profile ADD CONSTRAINT FK_72B5CDE4CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE specific_profile ADD CONSTRAINT FK_72B5CDE461F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE specific_profile_assignment ADD CONSTRAINT FK_A5A5F2C9DF5533E FOREIGN KEY (specific_profile_id) REFERENCES specific_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE specific_profile_assignment ADD CONSTRAINT FK_A5A5F2C9CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id)');
        $this->addSql('ALTER TABLE specific_profile_assignment ADD CONSTRAINT FK_A5A5F2C941807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id)');
        $this->addSql('ALTER TABLE list_item ADD CONSTRAINT FK_5AD5FAF7727ACA70 FOREIGN KEY (parent_id) REFERENCES list_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE list_item ADD CONSTRAINT FK_5AD5FAF761F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE list_item_tag ADD CONSTRAINT FK_9AF1FFD1CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE list_item_tag ADD CONSTRAINT FK_9AF1FFD1BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tag ADD CONSTRAINT FK_389B78361F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_section ADD CONSTRAINT FK_891CDC33727ACA70 FOREIGN KEY (parent_id) REFERENCES document_section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_section ADD CONSTRAINT FK_891CDC3361F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_section_profile ADD CONSTRAINT FK_5ADC66DE79E0482C FOREIGN KEY (document_section_id) REFERENCES document_section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_section_profile ADD CONSTRAINT FK_5ADC66DEDF5533E FOREIGN KEY (specific_profile_id) REFERENCES specific_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_section_profile ADD CONSTRAINT FK_5ADC66DECE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE email_notification_log ADD CONSTRAINT FK_E8B54561F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE email_notification_log ADD CONSTRAINT FK_E8B545E92F8F78 FOREIGN KEY (recipient_id) REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE global_setting_value ADD CONSTRAINT FK_466B8E04D11EA911 FOREIGN KEY (definition_id) REFERENCES setting_definition (id)');
        $this->addSql('ALTER TABLE global_setting_value ADD CONSTRAINT FK_466B8E0493CB796C FOREIGN KEY (file_id) REFERENCES setting_file (id)');
        $this->addSql('ALTER TABLE non_working_day ADD CONSTRAINT FK_32411A39C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id)');
        $this->addSql('ALTER TABLE school_event ADD CONSTRAINT FK_E554BCBDC54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id)');
        $this->addSql('ALTER TABLE school_event_profile ADD CONSTRAINT FK_2812AF9E8FB1DCCF FOREIGN KEY (school_event_id) REFERENCES school_event (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE school_event_profile ADD CONSTRAINT FK_2812AF9EDF5533E FOREIGN KEY (specific_profile_id) REFERENCES specific_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE school_event_profile ADD CONSTRAINT FK_2812AF9ECE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE teacher_setting_value ADD CONSTRAINT FK_9C9E2521D11EA911 FOREIGN KEY (definition_id) REFERENCES setting_definition (id)');
        $this->addSql('ALTER TABLE teacher_setting_value ADD CONSTRAINT FK_9C9E252141807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id)');
        $this->addSql('ALTER TABLE teacher_setting_value ADD CONSTRAINT FK_9C9E252193CB796C FOREIGN KEY (file_id) REFERENCES setting_file (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE academic_year DROP FOREIGN KEY FK_275AE72161F9EE23');
        $this->addSql('ALTER TABLE teacher_academic_year DROP FOREIGN KEY FK_EF1B6955C54F3401');
        $this->addSql('ALTER TABLE teacher_academic_year DROP FOREIGN KEY FK_EF1B695541807E1D');
        $this->addSql('ALTER TABLE centre_setting_value DROP FOREIGN KEY FK_306FFD17D11EA911');
        $this->addSql('ALTER TABLE centre_setting_value DROP FOREIGN KEY FK_306FFD17463CD7C3');
        $this->addSql('ALTER TABLE centre_setting_value DROP FOREIGN KEY FK_306FFD1793CB796C');
        $this->addSql('ALTER TABLE educational_centre DROP FOREIGN KEY FK_2E7EDDDC3B9B1771');
        $this->addSql('ALTER TABLE educational_centre_admins DROP FOREIGN KEY FK_9F1F12EF61F9EE23');
        $this->addSql('ALTER TABLE educational_centre_admins DROP FOREIGN KEY FK_9F1F12EF41807E1D');
        $this->addSql('ALTER TABLE educational_centre_quality_managers DROP FOREIGN KEY FK_155CABE61F9EE23');
        $this->addSql('ALTER TABLE educational_centre_quality_managers DROP FOREIGN KEY FK_155CABE41807E1D');
        $this->addSql('ALTER TABLE educational_centre_internal_auditors DROP FOREIGN KEY FK_EE6B277561F9EE23');
        $this->addSql('ALTER TABLE educational_centre_internal_auditors DROP FOREIGN KEY FK_EE6B277541807E1D');
        $this->addSql('ALTER TABLE specific_profile DROP FOREIGN KEY FK_72B5CDE4CE208F53');
        $this->addSql('ALTER TABLE specific_profile DROP FOREIGN KEY FK_72B5CDE461F9EE23');
        $this->addSql('ALTER TABLE specific_profile_assignment DROP FOREIGN KEY FK_A5A5F2C9DF5533E');
        $this->addSql('ALTER TABLE specific_profile_assignment DROP FOREIGN KEY FK_A5A5F2C9CE208F53');
        $this->addSql('ALTER TABLE specific_profile_assignment DROP FOREIGN KEY FK_A5A5F2C941807E1D');
        $this->addSql('ALTER TABLE list_item DROP FOREIGN KEY FK_5AD5FAF7727ACA70');
        $this->addSql('ALTER TABLE list_item DROP FOREIGN KEY FK_5AD5FAF761F9EE23');
        $this->addSql('ALTER TABLE list_item_tag DROP FOREIGN KEY FK_9AF1FFD1CE208F53');
        $this->addSql('ALTER TABLE list_item_tag DROP FOREIGN KEY FK_9AF1FFD1BAD26311');
        $this->addSql('ALTER TABLE tag DROP FOREIGN KEY FK_389B78361F9EE23');
        $this->addSql('ALTER TABLE document_section DROP FOREIGN KEY FK_891CDC33727ACA70');
        $this->addSql('ALTER TABLE document_section DROP FOREIGN KEY FK_891CDC3361F9EE23');
        $this->addSql('ALTER TABLE document_section_profile DROP FOREIGN KEY FK_5ADC66DE79E0482C');
        $this->addSql('ALTER TABLE document_section_profile DROP FOREIGN KEY FK_5ADC66DEDF5533E');
        $this->addSql('ALTER TABLE document_section_profile DROP FOREIGN KEY FK_5ADC66DECE208F53');
        $this->addSql('ALTER TABLE email_notification_log DROP FOREIGN KEY FK_E8B54561F9EE23');
        $this->addSql('ALTER TABLE email_notification_log DROP FOREIGN KEY FK_E8B545E92F8F78');
        $this->addSql('ALTER TABLE global_setting_value DROP FOREIGN KEY FK_466B8E04D11EA911');
        $this->addSql('ALTER TABLE global_setting_value DROP FOREIGN KEY FK_466B8E0493CB796C');
        $this->addSql('ALTER TABLE non_working_day DROP FOREIGN KEY FK_32411A39C54F3401');
        $this->addSql('ALTER TABLE school_event DROP FOREIGN KEY FK_E554BCBDC54F3401');
        $this->addSql('ALTER TABLE school_event_profile DROP FOREIGN KEY FK_2812AF9E8FB1DCCF');
        $this->addSql('ALTER TABLE school_event_profile DROP FOREIGN KEY FK_2812AF9EDF5533E');
        $this->addSql('ALTER TABLE school_event_profile DROP FOREIGN KEY FK_2812AF9ECE208F53');
        $this->addSql('ALTER TABLE teacher_setting_value DROP FOREIGN KEY FK_9C9E2521D11EA911');
        $this->addSql('ALTER TABLE teacher_setting_value DROP FOREIGN KEY FK_9C9E252141807E1D');
        $this->addSql('ALTER TABLE teacher_setting_value DROP FOREIGN KEY FK_9C9E252193CB796C');
        $this->addSql('DROP TABLE academic_year');
        $this->addSql('DROP TABLE teacher_academic_year');
        $this->addSql('DROP TABLE centre_setting_value');
        $this->addSql('DROP TABLE educational_centre');
        $this->addSql('DROP TABLE educational_centre_admins');
        $this->addSql('DROP TABLE educational_centre_quality_managers');
        $this->addSql('DROP TABLE educational_centre_internal_auditors');
        $this->addSql('DROP TABLE specific_profile');
        $this->addSql('DROP TABLE specific_profile_assignment');
        $this->addSql('DROP TABLE list_item');
        $this->addSql('DROP TABLE list_item_tag');
        $this->addSql('DROP TABLE tag');
        $this->addSql('DROP TABLE document_section');
        $this->addSql('DROP TABLE document_section_profile');
        $this->addSql('DROP TABLE email_notification_log');
        $this->addSql('DROP TABLE global_setting_value');
        $this->addSql('DROP TABLE non_working_day');
        $this->addSql('DROP TABLE school_event');
        $this->addSql('DROP TABLE school_event_profile');
        $this->addSql('DROP TABLE setting_definition');
        $this->addSql('DROP TABLE setting_file');
        $this->addSql('DROP TABLE teacher');
        $this->addSql('DROP TABLE teacher_setting_value');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
