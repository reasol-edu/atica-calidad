<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CentreSettingValue;
use App\Entity\EducationalCentre;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Service\AppSettingsInterface;
use App\Service\PdfHeaderBuilder;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PdfHeaderBuilderTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private PdfHeaderBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        // PdfHeaderBuilder is only ever consumed by one other service, so the compiled
        // container inlines it — it has no public service id of its own to fetch. Build it
        // directly from its real, container-provided dependencies instead.
        $this->builder = new PdfHeaderBuilder(
            self::getContainer()->get(AppSettingsInterface::class),
            self::getContainer()->get('html_sanitizer.sanitizer.app.rich_text'),
            self::getContainer()->get(TranslatorInterface::class),
            self::getContainer()->get('clock'),
        );
    }

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Sevilla');
    }

    private function definition(string $key, string $default = ''): SettingDefinition
    {
        return (new SettingDefinition())->setKey($key)->setType(SettingType::RichText)->setDefaultValue($default)->setCentreScope(true);
    }

    public function testBuildReplacesACustomPlaceholder(): void
    {
        $centre = $this->centre();
        $def    = $this->definition('reports.incident_header_left');
        $value  = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('<p>{title}</p>');
        $marginDef = (new SettingDefinition())->setKey('reports.incident_header_margin')->setType(SettingType::Integer)->setDefaultValue('22')->setCentreScope(true);
        $this->persist($centre, $def, $value, $marginDef);

        $header = $this->builder->build('incident', $centre, ['title' => 'Informe de incidencia']);

        self::assertStringContainsString('Informe de incidencia', $header->leftHtml);
    }

    public function testBuildReplacesTheAutomaticCityPlaceholder(): void
    {
        $centre = $this->centre();
        $def    = $this->definition('reports.incident_header_right');
        $value  = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('<p>{city}</p>');
        $this->persist($centre, $def, $value);

        $header = $this->builder->build('incident', $centre, []);

        self::assertStringContainsString('Sevilla', $header->rightHtml);
    }

    public function testBuildReplacesTheAutomaticDatePlaceholderUsingTheClock(): void
    {
        self::mockTime('2025-03-15 10:00:00');

        $centre = $this->centre();
        $def    = $this->definition('reports.incident_header_left');
        $value  = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('<p>{current_date}</p>');
        $this->persist($centre, $def, $value);

        $header = $this->builder->build('incident', $centre, []);

        self::assertStringContainsString('15/03/2025', $header->leftHtml);
    }

    public function testUnknownPlaceholdersSurviveAsPlainTextTokens(): void
    {
        $centre = $this->centre();
        $def    = $this->definition('reports.incident_header_left');
        $value  = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('<p>{no_such_placeholder}</p>');
        $this->persist($centre, $def, $value);

        $header = $this->builder->build('incident', $centre, []);

        self::assertStringContainsString('{no_such_placeholder}', $header->leftHtml);
    }

    public function testHtmlInAPlaceholderValueIsEscapedNotInjected(): void
    {
        $centre = $this->centre();
        $def    = $this->definition('reports.incident_header_left');
        $value  = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('<p>{title}</p>');
        $this->persist($centre, $def, $value);

        $header = $this->builder->build('incident', $centre, ['title' => '<script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>', $header->leftHtml);
    }

    public function testDisallowedHtmlInTheSettingItselfIsSanitizedAway(): void
    {
        $centre = $this->centre();
        $def    = $this->definition('reports.incident_header_left');
        $value  = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('<p onclick="evil()">Texto</p><script>evil()</script>');
        $this->persist($centre, $def, $value);

        $header = $this->builder->build('incident', $centre, []);

        self::assertStringNotContainsString('onclick', $header->leftHtml);
        self::assertStringNotContainsString('<script>', $header->leftHtml);
        self::assertStringContainsString('Texto', $header->leftHtml);
    }

    public function testMarginDefaultsToTwentyTwoWhenUnset(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $header = $this->builder->build('incident', $centre, []);

        self::assertSame(22, $header->marginTopMm);
    }

    public function testEmptyHeaderSettingResultsInAnEmptyString(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $header = $this->builder->build('incident', $centre, []);

        self::assertSame('', $header->leftHtml);
    }

    public function testBuildFooterResolvesPlaceholdersTheSameWay(): void
    {
        $centre = $this->centre();
        $def    = (new SettingDefinition())->setKey('reports.incident_footer')->setType(SettingType::RichText)->setDefaultValue('')->setCentreScope(true);
        $value  = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('<p>{city}</p>');
        $this->persist($centre, $def, $value);

        $footer = $this->builder->buildFooter('incident', $centre, []);

        self::assertStringContainsString('Sevilla', $footer);
    }
}
