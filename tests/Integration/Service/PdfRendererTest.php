<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CentreSettingValue;
use App\Entity\EducationalCentre;
use App\Entity\SettingDefinition;
use App\Entity\SettingFile;
use App\Entity\SettingType;
use App\Service\PdfHeader;
use App\Service\PdfRenderer;
use App\Service\PdfTemplateResolver;
use App\Service\PdfTemplateValidator;
use App\Tests\Integration\RepositoryTestCase;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class PdfRendererTest extends RepositoryTestCase
{
    private PdfRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        // PdfRenderer has no other consumer yet (the reports feature is only a
        // stub), so the compiled container inlines it away — build it directly.
        $this->renderer = new PdfRenderer(
            self::getContainer()->get(Environment::class),
            self::getContainer()->get(TranslatorInterface::class),
            self::getContainer()->get('clock'),
            new PdfTemplateResolver(self::getContainer()->get(\App\Service\AppSettingsInterface::class)),
        );
    }

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Sevilla');
    }

    public function testRendersAValidPdfWithTheExpectedContentTypeAndFilename(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $response = $this->renderer->render(
            'pdf/_header.html.twig',
            ['centre' => $centre],
            'Informe de prueba',
            'informe.pdf',
        );

        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $response->getContent());
        self::assertNull((new PdfTemplateValidator())->validate((string) $response->getContent(), 'P'));
    }

    public function testInlineFalseProducesAnAttachmentDisposition(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $response = $this->renderer->render(
            'pdf/_header.html.twig',
            ['centre' => $centre],
            'Informe',
            'descarga.pdf',
            inline: false,
        );

        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringStartsWith(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $disposition);
        self::assertStringContainsString('descarga.pdf', $disposition);
    }

    public function testInlineTrueProducesAnInlineDisposition(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $response = $this->renderer->render(
            'pdf/_header.html.twig',
            ['centre' => $centre],
            'Informe',
            'linea.pdf',
        );

        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringStartsWith(ResponseHeaderBag::DISPOSITION_INLINE, $disposition);
    }

    public function testLandscapeOrientationProducesALandscapePage(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $response = $this->renderer->render(
            'pdf/_header.html.twig',
            ['centre' => $centre],
            'Informe apaisado',
            'informe.pdf',
            orientation: 'L',
        );

        self::assertNull((new PdfTemplateValidator())->validate((string) $response->getContent(), 'L'));
    }

    public function testACustomHeaderIsUsedInsteadOfTheDefaultTitleAndCentreName(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $response = $this->renderer->render(
            'pdf/_header.html.twig',
            ['centre' => $centre],
            'Informe',
            'informe.pdf',
            header: new PdfHeader('<p>Izquierda</p>', '<p>Derecha</p>', 30),
        );

        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function testDraftWatermarkDoesNotBreakGeneration(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $response = $this->renderer->render(
            'pdf/_header.html.twig',
            ['centre' => $centre],
            'Borrador',
            'borrador.pdf',
            draftWatermark: true,
        );

        self::assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function testAppliesTheConfiguredBackgroundTemplateWhenReportTypeAndCentreAreGiven(): void
    {
        $centre = $this->centre();
        $def    = (new SettingDefinition())->setKey('reports.pdf_template_portrait')->setType(SettingType::Pdf)->setDefaultValue('')->setCentreScope(true);

        $bgMpdf = new Mpdf(['format' => 'A4', 'orientation' => 'P', 'tempDir' => sys_get_temp_dir()]);
        $bgMpdf->WriteHTML('<p>Fondo</p>');
        $backgroundPdf = $bgMpdf->Output('', Destination::STRING_RETURN);
        self::assertIsString($backgroundPdf);

        $file  = new SettingFile(hash('sha256', $backgroundPdf), $backgroundPdf, 'application/pdf', strlen($backgroundPdf));
        $value = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('fondo.pdf')->setFile($file);
        $this->persist($centre, $def, $file, $value);

        $response = $this->renderer->render(
            'pdf/_header.html.twig',
            ['centre' => $centre],
            'Informe con fondo',
            'informe.pdf',
            centre: $centre,
            reportType: 'incident',
        );

        self::assertStringStartsWith('%PDF', (string) $response->getContent());
    }
}
