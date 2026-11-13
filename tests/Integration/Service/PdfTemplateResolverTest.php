<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CentreSettingValue;
use App\Entity\EducationalCentre;
use App\Entity\SettingDefinition;
use App\Entity\SettingFile;
use App\Entity\SettingType;
use App\Service\AppSettingsInterface;
use App\Service\PdfTemplateResolver;
use App\Tests\Integration\RepositoryTestCase;

final class PdfTemplateResolverTest extends RepositoryTestCase
{
    private PdfTemplateResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        // Only consumed by PdfRenderer, so the compiled container inlines it — build it
        // directly instead of fetching a public service id that doesn't exist.
        $this->resolver = new PdfTemplateResolver(self::getContainer()->get(AppSettingsInterface::class));
    }

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Sevilla');
    }

    private function pdfDefinition(string $key): SettingDefinition
    {
        return (new SettingDefinition())->setKey($key)->setType(SettingType::Pdf)->setDefaultValue('')->setCentreScope(true);
    }

    public function testResolveReturnsNullWhenNoTemplateIsConfigured(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        self::assertNull($this->resolver->resolve('incident', 'P', $centre));
    }

    public function testResolveFallsBackToTheGeneralPortraitTemplate(): void
    {
        $centre = $this->centre();
        $def    = $this->pdfDefinition('reports.pdf_template_portrait');
        $file   = new SettingFile('hash-portrait', 'contenido', 'application/pdf', 9);
        $value  = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('fondo.pdf')->setFile($file);
        $this->persist($centre, $def, $file, $value);

        $resolved = $this->resolver->resolve('incident', 'P', $centre);

        self::assertNotNull($resolved);
        self::assertSame('fondo.pdf', $resolved->filename);
    }

    public function testResolveFallsBackToTheGeneralLandscapeTemplate(): void
    {
        $centre = $this->centre();
        $def    = $this->pdfDefinition('reports.pdf_template_landscape');
        $file   = new SettingFile('hash-landscape', 'contenido', 'application/pdf', 9);
        $value  = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('apaisado.pdf')->setFile($file);
        $this->persist($centre, $def, $file, $value);

        $resolved = $this->resolver->resolve('group_stats', 'L', $centre);

        self::assertNotNull($resolved);
        self::assertSame('apaisado.pdf', $resolved->filename);
    }

    public function testResolvePrefersTheSpecificReportTemplateOverTheGeneralOne(): void
    {
        $centre = $this->centre();

        $generalDef  = $this->pdfDefinition('reports.pdf_template_portrait');
        $generalFile = new SettingFile('hash-general', 'general', 'application/pdf', 7);
        $generalValue = (new CentreSettingValue())->setDefinition($generalDef)->setCentre($centre)->setValue('general.pdf')->setFile($generalFile);

        $specificDef  = $this->pdfDefinition('reports.incident_pdf_template');
        $specificFile = new SettingFile('hash-specific', 'especifico', 'application/pdf', 10);
        $specificValue = (new CentreSettingValue())->setDefinition($specificDef)->setCentre($centre)->setValue('incidencia.pdf')->setFile($specificFile);

        $this->persist($centre, $generalDef, $generalFile, $generalValue, $specificDef, $specificFile, $specificValue);

        $resolved = $this->resolver->resolve('incident', 'P', $centre);

        self::assertNotNull($resolved);
        self::assertSame('incidencia.pdf', $resolved->filename);
    }

    public function testResolveUsesTheGeneralTemplateForAReportTypeWithoutItsOwnSpecificOne(): void
    {
        $centre = $this->centre();
        $def    = $this->pdfDefinition('reports.pdf_template_portrait');
        $file   = new SettingFile('hash-portrait-2', 'contenido', 'application/pdf', 9);
        $value  = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('fondo.pdf')->setFile($file);
        $this->persist($centre, $def, $file, $value);

        $resolved = $this->resolver->resolve('sanction', 'P', $centre);

        self::assertNotNull($resolved);
        self::assertSame('fondo.pdf', $resolved->filename);
    }
}
