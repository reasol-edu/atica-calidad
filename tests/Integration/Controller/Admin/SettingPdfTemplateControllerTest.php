<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\CentreSettingValue;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingFile;
use App\Entity\SettingType;
use App\Entity\Teacher;
use App\Repository\CentreSettingValueRepository;
use App\Repository\SettingDefinitionRepository;
use App\Repository\SettingFileRepository;
use App\Tests\Integration\ControllerTestCase;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SettingPdfTemplateControllerTest extends ControllerTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    private function pdfDefinition(string $key): SettingDefinition
    {
        return (new SettingDefinition())->setKey($key)->setType(SettingType::Pdf)->setDefaultValue('')->setCentreScope(true);
    }

    private function csrfToken(string $id): string
    {
        /** @var \Symfony\Component\HttpFoundation\RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $request      = $this->client->getRequest();
        $requestStack->push($request);
        try {
            $token = self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
            $request->getSession()->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }

    private function singlePagePortraitPdf(): string
    {
        $mpdf = new Mpdf(['format' => 'A4', 'orientation' => 'P', 'tempDir' => sys_get_temp_dir()]);
        $mpdf->WriteHTML('<p>Plantilla</p>');

        $content = $mpdf->Output('', Destination::STRING_RETURN);
        self::assertIsString($content);

        return $content;
    }

    private function uploadedPdf(string $content, string $filename = 'plantilla.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf_template_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        return new UploadedFile($path, $filename, 'application/pdf', null, true);
    }

    public function testUploadDeniedForANonAdmin(): void
    {
        $centre  = $this->centre();
        $def     = $this->pdfDefinition('reports.pdf_template_portrait');
        $teacher = $this->teacher('docente');
        $this->persist($centre, $def, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', '/ajustes/plantillas-pdf/reports.pdf_template_portrait/subir', [
            '_token' => $this->csrfToken('settings_pdf_template_reports.pdf_template_portrait'),
        ], ['file' => $this->uploadedPdf($this->singlePagePortraitPdf())]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testUploadStoresAValidTemplateAndRedirects(): void
    {
        $centre = $this->centre();
        $def    = $this->pdfDefinition('reports.pdf_template_portrait');
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $def, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('POST', '/ajustes/plantillas-pdf/reports.pdf_template_portrait/subir', [
            '_token' => $this->csrfToken('settings_pdf_template_reports.pdf_template_portrait'),
        ], ['file' => $this->uploadedPdf($this->singlePagePortraitPdf())]);

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/ajustes"));

        $this->em->clear();
        /** @var SettingDefinitionRepository $definitions */
        $definitions = self::getContainer()->get(SettingDefinitionRepository::class);
        $reloadedDef = $definitions->findOneBy(['key' => 'reports.pdf_template_portrait']);
        self::assertNotNull($reloadedDef);
        /** @var \App\Repository\EducationalCentreRepository $centres */
        $centres        = self::getContainer()->get(\App\Repository\EducationalCentreRepository::class);
        $reloadedCentre = $centres->findById($centre->getId()->toRfc4122());
        self::assertNotNull($reloadedCentre);
        /** @var CentreSettingValueRepository $centreValues */
        $centreValues = self::getContainer()->get(CentreSettingValueRepository::class);
        $stored       = $centreValues->findByDefinitionAndCentre($reloadedDef, $reloadedCentre);
        self::assertNotNull($stored);
        self::assertNotNull($stored->getFile());
        self::assertSame('plantilla.pdf', $stored->getValue());
    }

    public function testUploadRejectsAWronglyOrientedTemplate(): void
    {
        $centre = $this->centre();
        // Landscape key expects an 'L' PDF; a portrait one must be rejected.
        $def   = $this->pdfDefinition('reports.pdf_template_landscape');
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $def, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('POST', '/ajustes/plantillas-pdf/reports.pdf_template_landscape/subir', [
            '_token' => $this->csrfToken('settings_pdf_template_reports.pdf_template_landscape'),
        ], ['file' => $this->uploadedPdf($this->singlePagePortraitPdf())]);

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/ajustes"));

        $this->em->clear();
        /** @var SettingDefinitionRepository $definitions */
        $definitions = self::getContainer()->get(SettingDefinitionRepository::class);
        $reloadedDef = $definitions->findOneBy(['key' => 'reports.pdf_template_landscape']);
        self::assertNotNull($reloadedDef);
        /** @var \App\Repository\EducationalCentreRepository $centres */
        $centres        = self::getContainer()->get(\App\Repository\EducationalCentreRepository::class);
        $reloadedCentre = $centres->findById($centre->getId()->toRfc4122());
        self::assertNotNull($reloadedCentre);
        /** @var CentreSettingValueRepository $centreValues */
        $centreValues = self::getContainer()->get(CentreSettingValueRepository::class);
        self::assertNull($centreValues->findByDefinitionAndCentre($reloadedDef, $reloadedCentre));
    }

    public function testUploadRejects404ForANonPdfDefinition(): void
    {
        $centre = $this->centre();
        $def    = (new SettingDefinition())->setKey('reports.title')->setType(SettingType::String)->setDefaultValue('');
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $def, $admin);

        $this->loginAs($admin, $centre);
        $this->client->request('POST', '/ajustes/plantillas-pdf/reports.title/subir', [
            '_token' => $this->csrfToken('settings_pdf_template_reports.title'),
        ], ['file' => $this->uploadedPdf($this->singlePagePortraitPdf())]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDownloadReturnsTheStoredFileContent(): void
    {
        $centre = $this->centre();
        $def    = $this->pdfDefinition('reports.pdf_template_portrait');
        $file   = new SettingFile('hash-download', 'contenido-pdf', 'application/pdf', 13);
        $value  = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('descarga.pdf')->setFile($file);
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $def, $file, $value, $admin);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/ajustes/plantillas-pdf/reports.pdf_template_portrait/descargar');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('contenido-pdf', $this->client->getResponse()->getContent());
    }

    public function testDownloadReturns404WhenNothingIsStored(): void
    {
        $centre = $this->centre();
        $def    = $this->pdfDefinition('reports.pdf_template_portrait');
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $def, $admin);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/ajustes/plantillas-pdf/reports.pdf_template_portrait/descargar');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDeleteRemovesTheStoredValueAndFile(): void
    {
        $centre = $this->centre();
        $def    = $this->pdfDefinition('reports.pdf_template_portrait');
        $file   = new SettingFile('hash-delete', 'contenido-pdf', 'application/pdf', 13);
        $value  = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue('borrar.pdf')->setFile($file);
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $def, $file, $value, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('POST', '/ajustes/plantillas-pdf/reports.pdf_template_portrait/eliminar', [
            '_token' => $this->csrfToken('settings_pdf_template_delete_reports.pdf_template_portrait'),
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/ajustes"));

        $this->em->clear();
        /** @var SettingDefinitionRepository $definitions */
        $definitions = self::getContainer()->get(SettingDefinitionRepository::class);
        $reloadedDef = $definitions->findOneBy(['key' => 'reports.pdf_template_portrait']);
        self::assertNotNull($reloadedDef);
        /** @var \App\Repository\EducationalCentreRepository $centres */
        $centres        = self::getContainer()->get(\App\Repository\EducationalCentreRepository::class);
        $reloadedCentre = $centres->findById($centre->getId()->toRfc4122());
        self::assertNotNull($reloadedCentre);
        /** @var CentreSettingValueRepository $centreValues */
        $centreValues = self::getContainer()->get(CentreSettingValueRepository::class);
        self::assertNull($centreValues->findByDefinitionAndCentre($reloadedDef, $reloadedCentre));

        /** @var SettingFileRepository $files */
        $files = self::getContainer()->get(SettingFileRepository::class);
        self::assertNull($files->findByHash('hash-delete'));
    }
}
