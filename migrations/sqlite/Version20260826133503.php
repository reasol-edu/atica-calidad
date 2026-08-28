<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260826133503 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE academic_year (id BLOB NOT NULL, name VARCHAR(50) NOT NULL, educational_centre_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_275AE72161F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_275AE72161F9EE23 ON academic_year (educational_centre_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_academic_year_centre ON academic_year (name, educational_centre_id)');
        $this->addSql('CREATE TABLE teacher_academic_year (academic_year_id BLOB NOT NULL, teacher_id BLOB NOT NULL, PRIMARY KEY (academic_year_id, teacher_id), CONSTRAINT FK_EF1B6955C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_EF1B695541807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_EF1B6955C54F3401 ON teacher_academic_year (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_EF1B695541807E1D ON teacher_academic_year (teacher_id)');
        $this->addSql('CREATE TABLE centre_setting_value (id BLOB NOT NULL, value CLOB NOT NULL, locked BOOLEAN NOT NULL, definition_id BLOB NOT NULL, centre_id BLOB NOT NULL, file_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_306FFD17D11EA911 FOREIGN KEY (definition_id) REFERENCES setting_definition (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_306FFD17463CD7C3 FOREIGN KEY (centre_id) REFERENCES educational_centre (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_306FFD1793CB796C FOREIGN KEY (file_id) REFERENCES setting_file (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_306FFD17D11EA911 ON centre_setting_value (definition_id)');
        $this->addSql('CREATE INDEX IDX_306FFD17463CD7C3 ON centre_setting_value (centre_id)');
        $this->addSql('CREATE INDEX IDX_306FFD1793CB796C ON centre_setting_value (file_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_centre_setting_def_centre ON centre_setting_value (definition_id, centre_id)');
        $this->addSql('CREATE TABLE educational_centre (id BLOB NOT NULL, code VARCHAR(8) NOT NULL, name VARCHAR(255) NOT NULL, city VARCHAR(255) NOT NULL, active_academic_year_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_2E7EDDDC3B9B1771 FOREIGN KEY (active_academic_year_id) REFERENCES academic_year (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2E7EDDDC77153098 ON educational_centre (code)');
        $this->addSql('CREATE INDEX IDX_2E7EDDDC3B9B1771 ON educational_centre (active_academic_year_id)');
        $this->addSql('CREATE TABLE educational_centre_admins (educational_centre_id BLOB NOT NULL, teacher_id BLOB NOT NULL, PRIMARY KEY (educational_centre_id, teacher_id), CONSTRAINT FK_9F1F12EF61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_9F1F12EF41807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_9F1F12EF61F9EE23 ON educational_centre_admins (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_9F1F12EF41807E1D ON educational_centre_admins (teacher_id)');
        $this->addSql('CREATE TABLE educational_centre_quality_managers (educational_centre_id BLOB NOT NULL, teacher_id BLOB NOT NULL, PRIMARY KEY (educational_centre_id, teacher_id), CONSTRAINT FK_155CABE61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_155CABE41807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_155CABE61F9EE23 ON educational_centre_quality_managers (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_155CABE41807E1D ON educational_centre_quality_managers (teacher_id)');
        $this->addSql('CREATE TABLE educational_centre_internal_auditors (educational_centre_id BLOB NOT NULL, teacher_id BLOB NOT NULL, PRIMARY KEY (educational_centre_id, teacher_id), CONSTRAINT FK_EE6B277561F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_EE6B277541807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_EE6B277561F9EE23 ON educational_centre_internal_auditors (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_EE6B277541807E1D ON educational_centre_internal_auditors (teacher_id)');
        $this->addSql('CREATE TABLE specific_profile (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, position INTEGER NOT NULL, active BOOLEAN NOT NULL, list_item_id BLOB DEFAULT NULL, educational_centre_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_72B5CDE4CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_72B5CDE461F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_72B5CDE4CE208F53 ON specific_profile (list_item_id)');
        $this->addSql('CREATE INDEX IDX_72B5CDE461F9EE23 ON specific_profile (educational_centre_id)');
        $this->addSql('CREATE TABLE specific_profile_assignment (id BLOB NOT NULL, specific_profile_id BLOB NOT NULL, list_item_id BLOB DEFAULT NULL, teacher_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_A5A5F2C9DF5533E FOREIGN KEY (specific_profile_id) REFERENCES specific_profile (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A5A5F2C9CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A5A5F2C941807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_A5A5F2C9DF5533E ON specific_profile_assignment (specific_profile_id)');
        $this->addSql('CREATE INDEX IDX_A5A5F2C9CE208F53 ON specific_profile_assignment (list_item_id)');
        $this->addSql('CREATE INDEX IDX_A5A5F2C941807E1D ON specific_profile_assignment (teacher_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_specific_profile_assignment ON specific_profile_assignment (specific_profile_id, list_item_id, teacher_id)');
        $this->addSql('CREATE TABLE list_item (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, position INTEGER NOT NULL, active BOOLEAN NOT NULL, parent_id BLOB DEFAULT NULL, educational_centre_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_5AD5FAF7727ACA70 FOREIGN KEY (parent_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5AD5FAF761F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_5AD5FAF7727ACA70 ON list_item (parent_id)');
        $this->addSql('CREATE INDEX IDX_5AD5FAF761F9EE23 ON list_item (educational_centre_id)');
        $this->addSql('CREATE TABLE list_item_tag (list_item_id BLOB NOT NULL, tag_id BLOB NOT NULL, PRIMARY KEY (list_item_id, tag_id), CONSTRAINT FK_9AF1FFD1CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_9AF1FFD1BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_9AF1FFD1CE208F53 ON list_item_tag (list_item_id)');
        $this->addSql('CREATE INDEX IDX_9AF1FFD1BAD26311 ON list_item_tag (tag_id)');
        $this->addSql('CREATE TABLE tag (id BLOB NOT NULL, name VARCHAR(100) NOT NULL, educational_centre_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_389B78361F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_389B78361F9EE23 ON tag (educational_centre_id)');
        $this->addSql('CREATE TABLE document_section (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, position INTEGER NOT NULL, parent_id BLOB DEFAULT NULL, educational_centre_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_891CDC33727ACA70 FOREIGN KEY (parent_id) REFERENCES document_section (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_891CDC3361F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_891CDC33727ACA70 ON document_section (parent_id)');
        $this->addSql('CREATE INDEX IDX_891CDC3361F9EE23 ON document_section (educational_centre_id)');
        $this->addSql('CREATE TABLE document_section_profile (id BLOB NOT NULL, document_section_id BLOB NOT NULL, specific_profile_id BLOB NOT NULL, list_item_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_5ADC66DE79E0482C FOREIGN KEY (document_section_id) REFERENCES document_section (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5ADC66DEDF5533E FOREIGN KEY (specific_profile_id) REFERENCES specific_profile (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5ADC66DECE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_5ADC66DE79E0482C ON document_section_profile (document_section_id)');
        $this->addSql('CREATE INDEX IDX_5ADC66DEDF5533E ON document_section_profile (specific_profile_id)');
        $this->addSql('CREATE INDEX IDX_5ADC66DECE208F53 ON document_section_profile (list_item_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_document_section_profile ON document_section_profile (document_section_id, specific_profile_id, list_item_id)');
        $this->addSql('CREATE TABLE folder (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, position INTEGER NOT NULL, group_by_profile BOOLEAN NOT NULL, auto_archive BOOLEAN NOT NULL, obsolete BOOLEAN NOT NULL, document_section_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_ECA209CD79E0482C FOREIGN KEY (document_section_id) REFERENCES document_section (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_ECA209CD79E0482C ON folder (document_section_id)');
        $this->addSql('CREATE TABLE folder_responsible_profile (id BLOB NOT NULL, folder_id BLOB NOT NULL, specific_profile_id BLOB NOT NULL, list_item_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_7B7BF33D162CB942 FOREIGN KEY (folder_id) REFERENCES folder (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7B7BF33DDF5533E FOREIGN KEY (specific_profile_id) REFERENCES specific_profile (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7B7BF33DCE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_7B7BF33D162CB942 ON folder_responsible_profile (folder_id)');
        $this->addSql('CREATE INDEX IDX_7B7BF33DDF5533E ON folder_responsible_profile (specific_profile_id)');
        $this->addSql('CREATE INDEX IDX_7B7BF33DCE208F53 ON folder_responsible_profile (list_item_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_folder_responsible_profile ON folder_responsible_profile (folder_id, specific_profile_id, list_item_id)');
        $this->addSql('CREATE TABLE folder_upload_profile (id BLOB NOT NULL, folder_id BLOB NOT NULL, specific_profile_id BLOB NOT NULL, list_item_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_70EA7E24162CB942 FOREIGN KEY (folder_id) REFERENCES folder (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_70EA7E24DF5533E FOREIGN KEY (specific_profile_id) REFERENCES specific_profile (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_70EA7E24CE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_70EA7E24162CB942 ON folder_upload_profile (folder_id)');
        $this->addSql('CREATE INDEX IDX_70EA7E24DF5533E ON folder_upload_profile (specific_profile_id)');
        $this->addSql('CREATE INDEX IDX_70EA7E24CE208F53 ON folder_upload_profile (list_item_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_folder_upload_profile ON folder_upload_profile (folder_id, specific_profile_id, list_item_id)');
        $this->addSql('CREATE TABLE folder_visibility_profile (id BLOB NOT NULL, folder_id BLOB NOT NULL, specific_profile_id BLOB NOT NULL, list_item_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_9B4F964F162CB942 FOREIGN KEY (folder_id) REFERENCES folder (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_9B4F964FDF5533E FOREIGN KEY (specific_profile_id) REFERENCES specific_profile (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_9B4F964FCE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_9B4F964F162CB942 ON folder_visibility_profile (folder_id)');
        $this->addSql('CREATE INDEX IDX_9B4F964FDF5533E ON folder_visibility_profile (specific_profile_id)');
        $this->addSql('CREATE INDEX IDX_9B4F964FCE208F53 ON folder_visibility_profile (list_item_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_folder_visibility_profile ON folder_visibility_profile (folder_id, specific_profile_id, list_item_id)');
        $this->addSql('CREATE TABLE folder_review_profile (id BLOB NOT NULL, folder_id BLOB NOT NULL, specific_profile_id BLOB NOT NULL, list_item_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_2E33D03D162CB942 FOREIGN KEY (folder_id) REFERENCES folder (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2E33D03DDF5533E FOREIGN KEY (specific_profile_id) REFERENCES specific_profile (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2E33D03DCE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_2E33D03D162CB942 ON folder_review_profile (folder_id)');
        $this->addSql('CREATE INDEX IDX_2E33D03DDF5533E ON folder_review_profile (specific_profile_id)');
        $this->addSql('CREATE INDEX IDX_2E33D03DCE208F53 ON folder_review_profile (list_item_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_folder_review_profile ON folder_review_profile (folder_id, specific_profile_id, list_item_id)');
        $this->addSql('CREATE TABLE document_file (id BLOB NOT NULL, hash VARCHAR(64) NOT NULL, content BLOB NOT NULL, mime_type VARCHAR(100) NOT NULL, original_filename VARCHAR(255) NOT NULL, size INTEGER NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_document_file_hash ON document_file (hash)');
        $this->addSql('CREATE TABLE document (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, uploaded_at DATETIME NOT NULL, position INTEGER NOT NULL, folder_id BLOB NOT NULL, upload_profile_id BLOB DEFAULT NULL, upload_list_item_id BLOB DEFAULT NULL, active_revision_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_D8698A76162CB942 FOREIGN KEY (folder_id) REFERENCES folder (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D8698A766716B003 FOREIGN KEY (upload_profile_id) REFERENCES specific_profile (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D8698A76F1C99108 FOREIGN KEY (upload_list_item_id) REFERENCES list_item (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D8698A76490589C4 FOREIGN KEY (active_revision_id) REFERENCES document_revision (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_D8698A76162CB942 ON document (folder_id)');
        $this->addSql('CREATE INDEX IDX_D8698A766716B003 ON document (upload_profile_id)');
        $this->addSql('CREATE INDEX IDX_D8698A76F1C99108 ON document (upload_list_item_id)');
        $this->addSql('CREATE INDEX IDX_D8698A76490589C4 ON document (active_revision_id)');
        $this->addSql('CREATE TABLE document_revision (id BLOB NOT NULL, version INTEGER NOT NULL, revised_at DATETIME NOT NULL, pending_review BOOLEAN NOT NULL, description CLOB DEFAULT NULL, rejected BOOLEAN NOT NULL, review_result CLOB DEFAULT NULL, document_id BLOB NOT NULL, reviewed_by_id BLOB DEFAULT NULL, file_id BLOB NOT NULL, uploaded_by_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_E2A2E495C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_E2A2E495FC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_E2A2E49593CB796C FOREIGN KEY (file_id) REFERENCES document_file (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_E2A2E495A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES teacher (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_E2A2E495C33F7837 ON document_revision (document_id)');
        $this->addSql('CREATE INDEX IDX_E2A2E495FC6B21F1 ON document_revision (reviewed_by_id)');
        $this->addSql('CREATE INDEX IDX_E2A2E49593CB796C ON document_revision (file_id)');
        $this->addSql('CREATE INDEX IDX_E2A2E495A2B28FE8 ON document_revision (uploaded_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_document_revision_version ON document_revision (document_id, version)');
        $this->addSql('CREATE TABLE email_notification_log (id BLOB NOT NULL, recipient_name VARCHAR(200) NOT NULL, event_key VARCHAR(50) NOT NULL, subject VARCHAR(255) NOT NULL, success BOOLEAN NOT NULL, error_message CLOB DEFAULT NULL, sent_at DATETIME NOT NULL, educational_centre_id BLOB NOT NULL, recipient_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_E8B54561F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_E8B545E92F8F78 FOREIGN KEY (recipient_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_E8B54561F9EE23 ON email_notification_log (educational_centre_id)');
        $this->addSql('CREATE INDEX idx_enl_centre_sent ON email_notification_log (educational_centre_id, sent_at)');
        $this->addSql('CREATE INDEX idx_enl_recipient ON email_notification_log (recipient_id)');
        $this->addSql('CREATE INDEX idx_enl_event ON email_notification_log (event_key)');
        $this->addSql('CREATE TABLE global_setting_value (id BLOB NOT NULL, value CLOB NOT NULL, locked BOOLEAN NOT NULL, definition_id BLOB NOT NULL, file_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_466B8E04D11EA911 FOREIGN KEY (definition_id) REFERENCES setting_definition (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_466B8E0493CB796C FOREIGN KEY (file_id) REFERENCES setting_file (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_466B8E0493CB796C ON global_setting_value (file_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_global_setting_definition ON global_setting_value (definition_id)');
        $this->addSql('CREATE TABLE non_working_day (id BLOB NOT NULL, date DATE NOT NULL, description VARCHAR(255) DEFAULT NULL, academic_year_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_32411A39C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_32411A39C54F3401 ON non_working_day (academic_year_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_non_working_day_year_date ON non_working_day (academic_year_id, date)');
        $this->addSql('CREATE TABLE school_event (id BLOB NOT NULL, date DATE NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, url VARCHAR(500) DEFAULT NULL, general BOOLEAN NOT NULL, academic_year_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_E554BCBDC54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_E554BCBDC54F3401 ON school_event (academic_year_id)');
        $this->addSql('CREATE TABLE school_event_profile (id BLOB NOT NULL, school_event_id BLOB NOT NULL, specific_profile_id BLOB NOT NULL, list_item_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_2812AF9E8FB1DCCF FOREIGN KEY (school_event_id) REFERENCES school_event (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2812AF9EDF5533E FOREIGN KEY (specific_profile_id) REFERENCES specific_profile (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2812AF9ECE208F53 FOREIGN KEY (list_item_id) REFERENCES list_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_2812AF9E8FB1DCCF ON school_event_profile (school_event_id)');
        $this->addSql('CREATE INDEX IDX_2812AF9EDF5533E ON school_event_profile (specific_profile_id)');
        $this->addSql('CREATE INDEX IDX_2812AF9ECE208F53 ON school_event_profile (list_item_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_school_event_profile ON school_event_profile (school_event_id, specific_profile_id, list_item_id)');
        $this->addSql('CREATE TABLE setting_definition (id BLOB NOT NULL, "key" VARCHAR(100) NOT NULL, type VARCHAR(255) NOT NULL, default_value CLOB NOT NULL, global_scope BOOLEAN NOT NULL, centre_scope BOOLEAN NOT NULL, teacher_scope BOOLEAN NOT NULL, min_value INTEGER DEFAULT NULL, max_value INTEGER DEFAULT NULL, choices VARCHAR(500) DEFAULT NULL, category VARCHAR(100) NOT NULL, category_order INTEGER NOT NULL, position INTEGER NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_setting_definition_key ON setting_definition ("key")');
        $this->addSql('CREATE TABLE setting_file (id BLOB NOT NULL, hash VARCHAR(64) NOT NULL, content BLOB NOT NULL, mime_type VARCHAR(100) NOT NULL, size INTEGER NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_setting_file_hash ON setting_file (hash)');
        $this->addSql('CREATE TABLE teacher (id BLOB NOT NULL, username VARCHAR(180) NOT NULL, admin BOOLEAN NOT NULL, password VARCHAR(255) DEFAULT NULL, external BOOLEAN NOT NULL, active BOOLEAN NOT NULL, force_password_change BOOLEAN NOT NULL, email VARCHAR(180) DEFAULT NULL, pending_email VARCHAR(180) DEFAULT NULL, email_verification_token VARCHAR(64) DEFAULT NULL, email_verification_token_expires_at DATETIME DEFAULT NULL, password_reset_token VARCHAR(64) DEFAULT NULL, password_reset_token_expires_at DATETIME DEFAULT NULL, name_first_name VARCHAR(255) NOT NULL, name_last_name VARCHAR(255) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B0F6A6D5F85E0677 ON teacher (username)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B0F6A6D5C4995C67 ON teacher (email_verification_token)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B0F6A6D56B7BA4B6 ON teacher (password_reset_token)');
        $this->addSql('CREATE TABLE teacher_setting_value (id BLOB NOT NULL, value VARCHAR(255) NOT NULL, definition_id BLOB NOT NULL, teacher_id BLOB NOT NULL, file_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_9C9E2521D11EA911 FOREIGN KEY (definition_id) REFERENCES setting_definition (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_9C9E252141807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_9C9E252193CB796C FOREIGN KEY (file_id) REFERENCES setting_file (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_9C9E2521D11EA911 ON teacher_setting_value (definition_id)');
        $this->addSql('CREATE INDEX IDX_9C9E252141807E1D ON teacher_setting_value (teacher_id)');
        $this->addSql('CREATE INDEX IDX_9C9E252193CB796C ON teacher_setting_value (file_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_teacher_setting_def_teacher ON teacher_setting_value (definition_id, teacher_id)');
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
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
        $this->addSql('DROP TABLE folder');
        $this->addSql('DROP TABLE folder_responsible_profile');
        $this->addSql('DROP TABLE folder_upload_profile');
        $this->addSql('DROP TABLE folder_visibility_profile');
        $this->addSql('DROP TABLE folder_review_profile');
        $this->addSql('DROP TABLE document_file');
        $this->addSql('DROP TABLE document');
        $this->addSql('DROP TABLE document_revision');
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
