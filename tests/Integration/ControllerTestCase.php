<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Teacher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ControllerTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // With SQLite :memory: every kernel reboot opens a fresh connection → empty DB.
        // Disabling the reboot keeps the same kernel (and DBAL connection) across all
        // requests within a single test, so the schema created below survives.
        $this->client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em       = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        (new SchemaTool($this->em))->createSchema(
            $this->em->getMetadataFactory()->getAllMetadata()
        );

        $this->seedDefaultSettings();
    }

    protected function tearDown(): void
    {
        (new SchemaTool($this->em))->dropSchema(
            $this->em->getMetadataFactory()->getAllMetadata()
        );

        parent::tearDown();
    }

    private function seedDefaultSettings(): void
    {
        // [key, type, default, globalScope, centreScope, teacherScope, minValue, maxValue, category, categoryOrder, position, choices]
        $defs = [
            ['page.size',                             SettingType::Integer, '20',   false, false, true,  5,    100,  'settings.category.display', 10, 10, null],
            ['email.notifications',                   SettingType::Boolean, 'true', true,  true,  true,  null, null, 'settings.category.email',   20, 10, null],
            ['email.notification.tutor_assigned',     SettingType::Boolean, 'true', true,  true,  true,  null, null, 'settings.category.email',   20, 20, null],
            ['email.notification.positions_created',  SettingType::Boolean, 'true', true,  true,  true,  null, null, 'settings.category.email',   20, 30, null],
            ['email.notification.signature_reminder', SettingType::Boolean, 'true', true,  true,  true,  null, null, 'settings.category.email',   20, 40, null],
            ['board.today_seconds',                    SettingType::Integer, '60',   true,  true,  false, 0,    3600, 'settings.category.board',   30, 5,  null],
            ['board.current_week_seconds',             SettingType::Integer, '10',   true,  true,  false, 0,    3600, 'settings.category.board',   30, 10, null],
            ['board.next_week_seconds',                SettingType::Integer, '0',    true,  true,  false, 0,    3600, 'settings.category.board',   30, 20, null],
            ['board.theme',                            SettingType::Choice,  'light', true,  true,  false, null, null, 'settings.category.board',   30, 30, 'light,dark,system'],
            ['notifications.report_auto_prescribe_days', SettingType::Integer, '14',  true,  true,  false, 0,    365,  'settings.category.notifications', 40, 30, null],
            ['notifications.report_prescription_warning_days', SettingType::Integer, '7', true,  true,  true,  0,    365,  'settings.category.notifications', 40, 40, null],
            ['notifications.email_log_enabled',                SettingType::Boolean, 'true', true, true,  false, null, null, 'settings.category.notifications', 40, 50, null],
            ['notifications.log_retention_days',               SettingType::Integer, '90',   true, false, false, 0,    3650, 'settings.category.notifications', 40, 60, null],
            ['reports.incident_header_left',   SettingType::RichText, '<p><strong>{title}</strong></p>', true, true, false, 0,  5000, 'settings.category.reports', 60, 10, null],
            ['reports.incident_header_right',  SettingType::RichText, '<p>{centre_name}</p>',            true, true, false, 0,  5000, 'settings.category.reports', 60, 20, null],
            ['reports.incident_header_margin', SettingType::Integer,  '22',                              true, true, false, 10, 80,   'settings.category.reports', 60, 30, null],
            ['reports.sanction_header_left',   SettingType::RichText, '<p><strong>{title}</strong></p>', true, true, false, 0,  5000, 'settings.category.reports', 60, 40, null],
            ['reports.sanction_header_right',  SettingType::RichText, '<p>{centre_name}</p>',            true, true, false, 0,  5000, 'settings.category.reports', 60, 50, null],
            ['reports.sanction_header_margin', SettingType::Integer,  '22',                              true, true, false, 10, 80,   'settings.category.reports', 60, 60, null],
            ['notifications.email_report_attach_pdf',   SettingType::Boolean, 'false', true, true, false, null, null, 'settings.category.email_alerts', 50, 90,  null],
            ['notifications.email_sanction_attach_pdf', SettingType::Boolean, 'false', true, true, false, null, null, 'settings.category.email_alerts', 50, 100, null],
            ['reports.draft_watermark_enabled', SettingType::Boolean, 'false', true, true, false, null, null, 'settings.category.reports', 60, 70, null],
            ['reports.group_stats_header_left',   SettingType::RichText, '<p><strong>{title}</strong></p>', true, true, false, 0,  5000, 'settings.category.reports', 60, 80,  null],
            ['reports.group_stats_header_right',  SettingType::RichText, '<p>{centre_name}</p>',            true, true, false, 0,  5000, 'settings.category.reports', 60, 90,  null],
            ['reports.group_stats_header_margin', SettingType::Integer,  '22',                              true, true, false, 10, 80,   'settings.category.reports', 60, 100, null],
            ['reports.incident_footer', SettingType::RichText, '<p>En {city} a {current_day} de {current_month_name} de {current_year}</p>', true, true, false, 0, 5000, 'settings.category.reports', 60, 110, null],
            ['reports.sanction_footer', SettingType::RichText, '<p>En {city} a {current_day} de {current_month_name} de {current_year}</p>', true, true, false, 0, 5000, 'settings.category.reports', 60, 120, null],
            ['reports.pdf_template_portrait',    SettingType::Pdf, '', false, true, false, null, null, 'settings.category.report_templates', 65, 10, null],
            ['reports.pdf_template_landscape',   SettingType::Pdf, '', false, true, false, null, null, 'settings.category.report_templates', 65, 20, null],
            ['reports.incident_pdf_template',    SettingType::Pdf, '', false, true, false, null, null, 'settings.category.report_templates', 65, 30, null],
            ['reports.sanction_pdf_template',    SettingType::Pdf, '', false, true, false, null, null, 'settings.category.report_templates', 65, 40, null],
            ['reports.group_stats_pdf_template', SettingType::Pdf, '', false, true, false, null, null, 'settings.category.report_templates', 65, 50, null],
            ['reports.guard_duty_pdf_template',  SettingType::Pdf, '', false, true, false, null, null, 'settings.category.report_templates', 65, 60, null],
        ];

        foreach ($defs as [$key, $type, $default, $global, $centre, $teacher, $min, $max, $category, $categoryOrder, $position, $choices]) {
            $def = (new SettingDefinition())
                ->setKey($key)
                ->setType($type)
                ->setDefaultValue($default)
                ->setGlobalScope($global)
                ->setCentreScope($centre)
                ->setTeacherScope($teacher)
                ->setMinValue($min)
                ->setMaxValue($max)
                ->setCategory($category)
                ->setCategoryOrder($categoryOrder)
                ->setPosition($position)
                ->setChoices($choices);
            $this->em->persist($def);
        }

        $this->em->flush();
    }

    protected function persist(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    protected function flush(): void
    {
        $this->em->flush();
    }

    /**
     * Returns the body of a StreamedResponse. KernelBrowser already consumes
     * the stream when filtering the response, so it must be read from the
     * BrowserKit internal response instead of sending the content again.
     */
    protected function getStreamedContent(): string
    {
        return $this->client->getInternalResponse()->getContent();
    }

    /**
     * Logs in as the given teacher. Makes one request to establish the
     * session, then optionally injects the tenant centre into that session.
     */
    protected function loginAs(Teacher $teacher, ?EducationalCentre $centre = null): void
    {
        $this->client->loginUser($teacher);
        // One request is needed to materialise the session file before we can
        // add keys to it. / is always accessible to an authenticated teacher.
        $this->client->request('GET', '/');

        if ($centre !== null) {
            $session = $this->client->getRequest()->getSession();
            $session->set('tenant.centre_id', $centre->getId()->toRfc4122());
            $session->save();
        }
    }

    /**
     * Simulates an admin switching to a past (non-active) academic year.
     * Must be called after loginAs() so the session already exists.
     */
    protected function viewPastYear(AcademicYear $year): void
    {
        $session = $this->client->getRequest()->getSession();
        $session->set('tenant.year_id', $year->getId()->toRfc4122());
        $session->save();
    }
}
