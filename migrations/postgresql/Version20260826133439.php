<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260826133439 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE academic_year (id UUID NOT NULL, name VARCHAR(50) NOT NULL, educational_centre_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_275AE72161F9EE23 ON academic_year (educational_centre_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_academic_year_centre ON academic_year (name, educational_centre_id)');
        $this->addSql('CREATE TABLE teacher_academic_year (academic_year_id UUID NOT NULL, teacher_id UUID NOT NULL, PRIMARY KEY (academic_year_id, teacher_id))');
        $this->addSql('CREATE INDEX IDX_EF1B6955C54F3401 ON teacher_academic_year (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_EF1B695541807E1D ON teacher_academic_year (teacher_id)');
        $this->addSql('CREATE TABLE centre_setting_value (id UUID NOT NULL, value TEXT NOT NULL, locked BOOLEAN NOT NULL, definition_id UUID NOT NULL, centre_id UUID NOT NULL, file_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_306FFD17D11EA911 ON centre_setting_value (definition_id)');
        $this->addSql('CREATE INDEX IDX_306FFD17463CD7C3 ON centre_setting_value (centre_id)');
        $this->addSql('CREATE INDEX IDX_306FFD1793CB796C ON centre_setting_value (file_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_centre_setting_def_centre ON centre_setting_value (definition_id, centre_id)');
        $this->addSql('CREATE TABLE course (id UUID NOT NULL, name VARCHAR(255) NOT NULL, details TEXT DEFAULT NULL, academic_year_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_169E6FB9C54F3401 ON course (academic_year_id)');
        $this->addSql('CREATE TABLE educational_centre (id UUID NOT NULL, code VARCHAR(8) NOT NULL, name VARCHAR(255) NOT NULL, city VARCHAR(255) NOT NULL, active_academic_year_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E7EDDDC77153098 ON educational_centre (code)');
        $this->addSql('CREATE INDEX IDX_2E7EDDDC3B9B1771 ON educational_centre (active_academic_year_id)');
        $this->addSql('CREATE TABLE educational_centre_admins (educational_centre_id UUID NOT NULL, teacher_id UUID NOT NULL, PRIMARY KEY (educational_centre_id, teacher_id))');
        $this->addSql('CREATE INDEX IDX_9F1F12EF61F9EE23 ON educational_centre_admins (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_9F1F12EF41807E1D ON educational_centre_admins (teacher_id)');
        $this->addSql('CREATE TABLE educational_centre_quality_managers (educational_centre_id UUID NOT NULL, teacher_id UUID NOT NULL, PRIMARY KEY (educational_centre_id, teacher_id))');
        $this->addSql('CREATE INDEX IDX_155CABE61F9EE23 ON educational_centre_quality_managers (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_155CABE41807E1D ON educational_centre_quality_managers (teacher_id)');
        $this->addSql('CREATE TABLE educational_centre_internal_auditors (educational_centre_id UUID NOT NULL, teacher_id UUID NOT NULL, PRIMARY KEY (educational_centre_id, teacher_id))');
        $this->addSql('CREATE INDEX IDX_EE6B277561F9EE23 ON educational_centre_internal_auditors (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_EE6B277541807E1D ON educational_centre_internal_auditors (teacher_id)');
        $this->addSql('CREATE TABLE specific_profile (id UUID NOT NULL, name VARCHAR(255) NOT NULL, position INT NOT NULL, active BOOLEAN NOT NULL, list_item_id UUID DEFAULT NULL, educational_centre_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_72B5CDE4CE208F53 ON specific_profile (list_item_id)');
        $this->addSql('CREATE INDEX IDX_72B5CDE461F9EE23 ON specific_profile (educational_centre_id)');
        $this->addSql('CREATE TABLE specific_profile_assignment (id UUID NOT NULL, specific_profile_id UUID NOT NULL, list_item_id UUID DEFAULT NULL, teacher_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A5A5F2C9DF5533E ON specific_profile_assignment (specific_profile_id)');
        $this->addSql('CREATE INDEX IDX_A5A5F2C9CE208F53 ON specific_profile_assignment (list_item_id)');
        $this->addSql('CREATE INDEX IDX_A5A5F2C941807E1D ON specific_profile_assignment (teacher_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_specific_profile_assignment ON specific_profile_assignment (specific_profile_id, list_item_id, teacher_id)');
        $this->addSql('CREATE TABLE list_item (id UUID NOT NULL, name VARCHAR(255) NOT NULL, position INT NOT NULL, active BOOLEAN NOT NULL, parent_id UUID DEFAULT NULL, educational_centre_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5AD5FAF7727ACA70 ON list_item (parent_id)');
        $this->addSql('CREATE INDEX IDX_5AD5FAF761F9EE23 ON list_item (educational_centre_id)');
        $this->addSql('CREATE TABLE list_item_tag (list_item_id UUID NOT NULL, tag_id UUID NOT NULL, PRIMARY KEY (list_item_id, tag_id))');
        $this->addSql('CREATE INDEX IDX_9AF1FFD1CE208F53 ON list_item_tag (list_item_id)');
        $this->addSql('CREATE INDEX IDX_9AF1FFD1BAD26311 ON list_item_tag (tag_id)');
        $this->addSql('CREATE TABLE tag (id UUID NOT NULL, name VARCHAR(100) NOT NULL, educational_centre_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_389B78361F9EE23 ON tag (educational_centre_id)');
        $this->addSql('CREATE TABLE email_notification_log (id UUID NOT NULL, recipient_name VARCHAR(200) NOT NULL, event_key VARCHAR(50) NOT NULL, subject VARCHAR(255) NOT NULL, success BOOLEAN NOT NULL, error_message TEXT DEFAULT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, educational_centre_id UUID NOT NULL, recipient_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E8B54561F9EE23 ON email_notification_log (educational_centre_id)');
        $this->addSql('CREATE INDEX idx_enl_centre_sent ON email_notification_log (educational_centre_id, sent_at)');
        $this->addSql('CREATE INDEX idx_enl_recipient ON email_notification_log (recipient_id)');
        $this->addSql('CREATE INDEX idx_enl_event ON email_notification_log (event_key)');
        $this->addSql('CREATE TABLE global_setting_value (id UUID NOT NULL, value TEXT NOT NULL, locked BOOLEAN NOT NULL, definition_id UUID NOT NULL, file_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_466B8E0493CB796C ON global_setting_value (file_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_global_setting_definition ON global_setting_value (definition_id)');
        $this->addSql('CREATE TABLE "group" (id UUID NOT NULL, name VARCHAR(255) NOT NULL, details TEXT DEFAULT NULL, course_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6DC044C5591CC992 ON "group" (course_id)');
        $this->addSql('CREATE TABLE group_tutor (group_id UUID NOT NULL, teacher_id UUID NOT NULL, PRIMARY KEY (group_id, teacher_id))');
        $this->addSql('CREATE INDEX IDX_C92B6D2FFE54D947 ON group_tutor (group_id)');
        $this->addSql('CREATE INDEX IDX_C92B6D2F41807E1D ON group_tutor (teacher_id)');
        $this->addSql('CREATE TABLE group_teacher (id UUID NOT NULL, subject VARCHAR(255) NOT NULL, group_id UUID NOT NULL, teacher_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_36F6F2D9FE54D947 ON group_teacher (group_id)');
        $this->addSql('CREATE INDEX IDX_36F6F2D941807E1D ON group_teacher (teacher_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_group_teacher_subject ON group_teacher (group_id, teacher_id, subject)');
        $this->addSql('CREATE TABLE non_working_day (id UUID NOT NULL, date DATE NOT NULL, description VARCHAR(255) DEFAULT NULL, academic_year_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_32411A39C54F3401 ON non_working_day (academic_year_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_non_working_day_year_date ON non_working_day (academic_year_id, date)');
        $this->addSql('CREATE TABLE school_event (id UUID NOT NULL, date DATE NOT NULL, start_time TIME(0) WITHOUT TIME ZONE NOT NULL, end_time TIME(0) WITHOUT TIME ZONE NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, url VARCHAR(500) DEFAULT NULL, general BOOLEAN NOT NULL, academic_year_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E554BCBDC54F3401 ON school_event (academic_year_id)');
        $this->addSql('CREATE TABLE school_event_group (school_event_id UUID NOT NULL, group_id UUID NOT NULL, PRIMARY KEY (school_event_id, group_id))');
        $this->addSql('CREATE INDEX IDX_7FDDC7598FB1DCCF ON school_event_group (school_event_id)');
        $this->addSql('CREATE INDEX IDX_7FDDC759FE54D947 ON school_event_group (group_id)');
        $this->addSql('CREATE TABLE setting_definition (id UUID NOT NULL, key VARCHAR(100) NOT NULL, type VARCHAR(255) NOT NULL, default_value TEXT NOT NULL, global_scope BOOLEAN NOT NULL, centre_scope BOOLEAN NOT NULL, teacher_scope BOOLEAN NOT NULL, min_value INT DEFAULT NULL, max_value INT DEFAULT NULL, choices VARCHAR(500) DEFAULT NULL, category VARCHAR(100) NOT NULL, category_order INT NOT NULL, position INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_setting_definition_key ON setting_definition (key)');
        $this->addSql('CREATE TABLE setting_file (id UUID NOT NULL, hash VARCHAR(64) NOT NULL, content BYTEA NOT NULL, mime_type VARCHAR(100) NOT NULL, size INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_setting_file_hash ON setting_file (hash)');
        $this->addSql('CREATE TABLE teacher (id UUID NOT NULL, username VARCHAR(180) NOT NULL, admin BOOLEAN NOT NULL, password VARCHAR(255) DEFAULT NULL, external BOOLEAN NOT NULL, active BOOLEAN NOT NULL, force_password_change BOOLEAN NOT NULL, email VARCHAR(180) DEFAULT NULL, pending_email VARCHAR(180) DEFAULT NULL, email_verification_token VARCHAR(64) DEFAULT NULL, email_verification_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, password_reset_token VARCHAR(64) DEFAULT NULL, password_reset_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, name_first_name VARCHAR(255) NOT NULL, name_last_name VARCHAR(255) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B0F6A6D5F85E0677 ON teacher (username)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B0F6A6D5C4995C67 ON teacher (email_verification_token)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B0F6A6D56B7BA4B6 ON teacher (password_reset_token)');
        $this->addSql('CREATE TABLE teacher_setting_value (id UUID NOT NULL, value VARCHAR(255) NOT NULL, definition_id UUID NOT NULL, teacher_id UUID NOT NULL, file_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9C9E2521D11EA911 ON teacher_setting_value (definition_id)');
        $this->addSql('CREATE INDEX IDX_9C9E252141807E1D ON teacher_setting_value (teacher_id)');
        $this->addSql('CREATE INDEX IDX_9C9E252193CB796C ON teacher_setting_value (file_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_teacher_setting_def_teacher ON teacher_setting_value (definition_id, teacher_id)');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
        $this->addSql('ALTER TABLE academic_year ADD CONSTRAINT FK_275AE72161F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE teacher_academic_year ADD CONSTRAINT FK_EF1B6955C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE teacher_academic_year ADD CONSTRAINT FK_EF1B695541807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE centre_setting_value ADD CONSTRAINT FK_306FFD17D11EA911 FOREIGN KEY (definition_id) REFERENCES setting_definition (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE centre_setting_value ADD CONSTRAINT FK_306FFD17463CD7C3 FOREIGN KEY (centre_id) REFERENCES educational_centre (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE centre_setting_value ADD CONSTRAINT FK_306FFD1793CB796C FOREIGN KEY (file_id) REFERENCES setting_file (id)');
        $this->addSql('ALTER TABLE course ADD CONSTRAINT FK_169E6FB9C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE educational_centre ADD CONSTRAINT FK_2E7EDDDC3B9B1771 FOREIGN KEY (active_academic_year_id) REFERENCES academic_year (id)');
        $this->addSql('ALTER TABLE educational_centre_admins ADD CONSTRAINT FK_9F1F12EF61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educational_centre_admins ADD CONSTRAINT FK_9F1F12EF41807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educational_centre_quality_managers ADD CONSTRAINT FK_155CABE61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educational_centre_quality_managers ADD CONSTRAINT FK_155CABE41807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educational_centre_internal_auditors ADD CONSTRAINT FK_EE6B277561F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educational_centre_internal_auditors ADD CONSTRAINT FK_EE6B277541807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE specific_profile ADD CONSTRAINT FK_72B5CDE4CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE specific_profile ADD CONSTRAINT FK_72B5CDE461F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE specific_profile_assignment ADD CONSTRAINT FK_A5A5F2C9DF5533E FOREIGN KEY (specific_profile_id) REFERENCES specific_profile (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE specific_profile_assignment ADD CONSTRAINT FK_A5A5F2C9CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE specific_profile_assignment ADD CONSTRAINT FK_A5A5F2C941807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE list_item ADD CONSTRAINT FK_5AD5FAF7727ACA70 FOREIGN KEY (parent_id) REFERENCES list_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE list_item ADD CONSTRAINT FK_5AD5FAF761F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE list_item_tag ADD CONSTRAINT FK_9AF1FFD1CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE list_item_tag ADD CONSTRAINT FK_9AF1FFD1BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tag ADD CONSTRAINT FK_389B78361F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE email_notification_log ADD CONSTRAINT FK_E8B54561F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE email_notification_log ADD CONSTRAINT FK_E8B545E92F8F78 FOREIGN KEY (recipient_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE global_setting_value ADD CONSTRAINT FK_466B8E04D11EA911 FOREIGN KEY (definition_id) REFERENCES setting_definition (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE global_setting_value ADD CONSTRAINT FK_466B8E0493CB796C FOREIGN KEY (file_id) REFERENCES setting_file (id)');
        $this->addSql('ALTER TABLE "group" ADD CONSTRAINT FK_6DC044C5591CC992 FOREIGN KEY (course_id) REFERENCES course (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_tutor ADD CONSTRAINT FK_C92B6D2FFE54D947 FOREIGN KEY (group_id) REFERENCES "group" (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE group_tutor ADD CONSTRAINT FK_C92B6D2F41807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE group_teacher ADD CONSTRAINT FK_36F6F2D9FE54D947 FOREIGN KEY (group_id) REFERENCES "group" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_teacher ADD CONSTRAINT FK_36F6F2D941807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE non_working_day ADD CONSTRAINT FK_32411A39C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE school_event ADD CONSTRAINT FK_E554BCBDC54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE school_event_group ADD CONSTRAINT FK_7FDDC7598FB1DCCF FOREIGN KEY (school_event_id) REFERENCES school_event (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE school_event_group ADD CONSTRAINT FK_7FDDC759FE54D947 FOREIGN KEY (group_id) REFERENCES "group" (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE teacher_setting_value ADD CONSTRAINT FK_9C9E2521D11EA911 FOREIGN KEY (definition_id) REFERENCES setting_definition (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE teacher_setting_value ADD CONSTRAINT FK_9C9E252141807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE teacher_setting_value ADD CONSTRAINT FK_9C9E252193CB796C FOREIGN KEY (file_id) REFERENCES setting_file (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE academic_year DROP CONSTRAINT FK_275AE72161F9EE23');
        $this->addSql('ALTER TABLE teacher_academic_year DROP CONSTRAINT FK_EF1B6955C54F3401');
        $this->addSql('ALTER TABLE teacher_academic_year DROP CONSTRAINT FK_EF1B695541807E1D');
        $this->addSql('ALTER TABLE centre_setting_value DROP CONSTRAINT FK_306FFD17D11EA911');
        $this->addSql('ALTER TABLE centre_setting_value DROP CONSTRAINT FK_306FFD17463CD7C3');
        $this->addSql('ALTER TABLE centre_setting_value DROP CONSTRAINT FK_306FFD1793CB796C');
        $this->addSql('ALTER TABLE course DROP CONSTRAINT FK_169E6FB9C54F3401');
        $this->addSql('ALTER TABLE educational_centre DROP CONSTRAINT FK_2E7EDDDC3B9B1771');
        $this->addSql('ALTER TABLE educational_centre_admins DROP CONSTRAINT FK_9F1F12EF61F9EE23');
        $this->addSql('ALTER TABLE educational_centre_admins DROP CONSTRAINT FK_9F1F12EF41807E1D');
        $this->addSql('ALTER TABLE educational_centre_quality_managers DROP CONSTRAINT FK_155CABE61F9EE23');
        $this->addSql('ALTER TABLE educational_centre_quality_managers DROP CONSTRAINT FK_155CABE41807E1D');
        $this->addSql('ALTER TABLE educational_centre_internal_auditors DROP CONSTRAINT FK_EE6B277561F9EE23');
        $this->addSql('ALTER TABLE educational_centre_internal_auditors DROP CONSTRAINT FK_EE6B277541807E1D');
        $this->addSql('ALTER TABLE specific_profile DROP CONSTRAINT FK_72B5CDE4CE208F53');
        $this->addSql('ALTER TABLE specific_profile DROP CONSTRAINT FK_72B5CDE461F9EE23');
        $this->addSql('ALTER TABLE specific_profile_assignment DROP CONSTRAINT FK_A5A5F2C9DF5533E');
        $this->addSql('ALTER TABLE specific_profile_assignment DROP CONSTRAINT FK_A5A5F2C9CE208F53');
        $this->addSql('ALTER TABLE specific_profile_assignment DROP CONSTRAINT FK_A5A5F2C941807E1D');
        $this->addSql('ALTER TABLE list_item DROP CONSTRAINT FK_5AD5FAF7727ACA70');
        $this->addSql('ALTER TABLE list_item DROP CONSTRAINT FK_5AD5FAF761F9EE23');
        $this->addSql('ALTER TABLE list_item_tag DROP CONSTRAINT FK_9AF1FFD1CE208F53');
        $this->addSql('ALTER TABLE list_item_tag DROP CONSTRAINT FK_9AF1FFD1BAD26311');
        $this->addSql('ALTER TABLE tag DROP CONSTRAINT FK_389B78361F9EE23');
        $this->addSql('ALTER TABLE email_notification_log DROP CONSTRAINT FK_E8B54561F9EE23');
        $this->addSql('ALTER TABLE email_notification_log DROP CONSTRAINT FK_E8B545E92F8F78');
        $this->addSql('ALTER TABLE global_setting_value DROP CONSTRAINT FK_466B8E04D11EA911');
        $this->addSql('ALTER TABLE global_setting_value DROP CONSTRAINT FK_466B8E0493CB796C');
        $this->addSql('ALTER TABLE "group" DROP CONSTRAINT FK_6DC044C5591CC992');
        $this->addSql('ALTER TABLE group_tutor DROP CONSTRAINT FK_C92B6D2FFE54D947');
        $this->addSql('ALTER TABLE group_tutor DROP CONSTRAINT FK_C92B6D2F41807E1D');
        $this->addSql('ALTER TABLE group_teacher DROP CONSTRAINT FK_36F6F2D9FE54D947');
        $this->addSql('ALTER TABLE group_teacher DROP CONSTRAINT FK_36F6F2D941807E1D');
        $this->addSql('ALTER TABLE non_working_day DROP CONSTRAINT FK_32411A39C54F3401');
        $this->addSql('ALTER TABLE school_event DROP CONSTRAINT FK_E554BCBDC54F3401');
        $this->addSql('ALTER TABLE school_event_group DROP CONSTRAINT FK_7FDDC7598FB1DCCF');
        $this->addSql('ALTER TABLE school_event_group DROP CONSTRAINT FK_7FDDC759FE54D947');
        $this->addSql('ALTER TABLE teacher_setting_value DROP CONSTRAINT FK_9C9E2521D11EA911');
        $this->addSql('ALTER TABLE teacher_setting_value DROP CONSTRAINT FK_9C9E252141807E1D');
        $this->addSql('ALTER TABLE teacher_setting_value DROP CONSTRAINT FK_9C9E252193CB796C');
        $this->addSql('DROP TABLE academic_year');
        $this->addSql('DROP TABLE teacher_academic_year');
        $this->addSql('DROP TABLE centre_setting_value');
        $this->addSql('DROP TABLE course');
        $this->addSql('DROP TABLE educational_centre');
        $this->addSql('DROP TABLE educational_centre_admins');
        $this->addSql('DROP TABLE educational_centre_quality_managers');
        $this->addSql('DROP TABLE educational_centre_internal_auditors');
        $this->addSql('DROP TABLE specific_profile');
        $this->addSql('DROP TABLE specific_profile_assignment');
        $this->addSql('DROP TABLE list_item');
        $this->addSql('DROP TABLE list_item_tag');
        $this->addSql('DROP TABLE tag');
        $this->addSql('DROP TABLE email_notification_log');
        $this->addSql('DROP TABLE global_setting_value');
        $this->addSql('DROP TABLE "group"');
        $this->addSql('DROP TABLE group_tutor');
        $this->addSql('DROP TABLE group_teacher');
        $this->addSql('DROP TABLE non_working_day');
        $this->addSql('DROP TABLE school_event');
        $this->addSql('DROP TABLE school_event_group');
        $this->addSql('DROP TABLE setting_definition');
        $this->addSql('DROP TABLE setting_file');
        $this->addSql('DROP TABLE teacher');
        $this->addSql('DROP TABLE teacher_setting_value');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
