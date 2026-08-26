<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentCentre;
use App\Controller\TranslatorTrait;
use App\Controller\UploadSizeGuardTrait;
use App\Entity\CentreSettingValue;
use App\Entity\EducationalCentre;
use App\Entity\SettingDefinition;
use App\Entity\SettingFile;
use App\Entity\SettingType;
use App\Repository\CentreSettingValueRepository;
use App\Repository\SettingDefinitionRepository;
use App\Repository\SettingFileRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\AppSettings;
use App\Service\AttachmentDownloadResponder;
use App\Service\PdfTemplateValidationError;
use App\Service\PdfTemplateValidator;
use App\Service\SettingFileGarbageCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/ajustes/plantillas-pdf/{key}')]
class SettingPdfTemplateController extends AbstractController
{
    use TranslatorTrait;
    use UploadSizeGuardTrait;

    private const MAX_TEMPLATE_SIZE = 10 * 1024 * 1024;

    /** Ajustes cuya orientación esperada es apaisada; el resto se validan como verticales. */
    private const LANDSCAPE_KEYS = ['reports.pdf_template_landscape'];

    public function __construct(
        protected readonly TranslatorInterface $translator,
        private readonly SettingDefinitionRepository $definitions,
        private readonly CentreSettingValueRepository $centreValues,
        private readonly SettingFileRepository $files,
        private readonly SettingFileGarbageCollector $garbageCollector,
        private readonly PdfTemplateValidator $validator,
        private readonly AttachmentDownloadResponder $downloadResponder,
        private readonly AppSettings $appSettings,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/subir', name: 'app_settings_pdf_template_upload', methods: ['POST'])]
    public function upload(string $key, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);
        $definition = $this->requireDefinition($key);

        if ($this->isUploadTooLarge($request)) {
            $this->addFlash('error', $this->t('settings.pdf_template.error.too_large'));

            return $this->redirectToCentreSettings($centre);
        }

        if (!$this->isCsrfTokenValid('settings_pdf_template_' . $key, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t('settings.pdf_template.error.no_file'));

            return $this->redirectToCentreSettings($centre);
        }

        if ($file->getSize() > self::MAX_TEMPLATE_SIZE) {
            $this->addFlash('error', $this->t('settings.pdf_template.error.too_large'));

            return $this->redirectToCentreSettings($centre);
        }

        if (($file->getMimeType() ?? 'application/octet-stream') !== 'application/pdf') {
            $this->addFlash('error', $this->t('settings.pdf_template.error.invalid_type'));

            return $this->redirectToCentreSettings($centre);
        }

        $content = (string) file_get_contents($file->getPathname());

        $validationError = $this->validator->validate($content, $this->expectedOrientationFor($key));
        if ($validationError !== null) {
            $this->addFlash('error', $this->t($this->validationErrorTranslationKey($validationError)));

            return $this->redirectToCentreSettings($centre);
        }

        $this->storeTemplate($definition, $centre, $content, $file->getClientOriginalName());

        $this->addFlash('success', $this->t('settings.pdf_template.flash.uploaded'));

        return $this->redirectToCentreSettings($centre);
    }

    #[Route('/eliminar', name: 'app_settings_pdf_template_delete', methods: ['POST'])]
    public function delete(string $key, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);
        $definition = $this->requireDefinition($key);

        if (!$this->isCsrfTokenValid('settings_pdf_template_delete_' . $key, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $value = $this->centreValues->findByDefinitionAndCentre($definition, $centre);
        if ($value !== null) {
            $file = $value->getFile();
            $this->em->remove($value);
            $this->em->flush();

            if ($file !== null) {
                $this->garbageCollector->deleteIfOrphaned($file);
            }

            $this->appSettings->invalidate();
            $this->addFlash('success', $this->t('settings.pdf_template.flash.deleted'));
        }

        return $this->redirectToCentreSettings($centre);
    }

    #[Route('/descargar', name: 'app_settings_pdf_template_download', methods: ['GET'])]
    public function download(string $key, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);
        $definition = $this->requireDefinition($key);

        $value = $this->centreValues->findByDefinitionAndCentre($definition, $centre);
        $file  = $value?->getFile();
        if ($value === null || $file === null) {
            throw $this->createNotFoundException();
        }

        return $this->downloadResponder->respond($file->getContent(), $file->getMimeType(), $value->getValue());
    }

    private function storeTemplate(SettingDefinition $definition, EducationalCentre $centre, string $content, string $filename): void
    {
        $hash        = hash('sha256', $content);
        $settingFile = $this->files->findByHash($hash);
        if ($settingFile === null) {
            $settingFile = new SettingFile($hash, $content, 'application/pdf', strlen($content));
            $this->em->persist($settingFile);
        }

        $value   = $this->centreValues->findByDefinitionAndCentre($definition, $centre);
        $oldFile = $value?->getFile();

        if ($value === null) {
            $value = (new CentreSettingValue())->setDefinition($definition)->setCentre($centre);
        }
        $value->setValue($filename)->setFile($settingFile);
        $this->em->persist($value);
        $this->em->flush();

        if ($oldFile !== null && $oldFile !== $settingFile) {
            $this->garbageCollector->deleteIfOrphaned($oldFile);
        }

        $this->appSettings->invalidate();
    }

    private function requireDefinition(string $key): SettingDefinition
    {
        $definition = $this->definitions->findOneBy(['key' => $key]);
        if ($definition === null || $definition->getType() !== SettingType::Pdf) {
            throw $this->createNotFoundException();
        }

        return $definition;
    }

    /** @return 'P'|'L' */
    private function expectedOrientationFor(string $key): string
    {
        return in_array($key, self::LANDSCAPE_KEYS, true) ? 'L' : 'P';
    }

    private function validationErrorTranslationKey(PdfTemplateValidationError $error): string
    {
        return match ($error) {
            PdfTemplateValidationError::InvalidPdf => 'settings.pdf_template.error.invalid_pdf',
            PdfTemplateValidationError::MultiPage => 'settings.pdf_template.error.multi_page',
            PdfTemplateValidationError::WrongOrientation => 'settings.pdf_template.error.wrong_orientation',
        };
    }

    private function translationDomain(): string
    {
        return 'settings';
    }

    private function redirectToCentreSettings(EducationalCentre $centre): Response
    {
        return $this->redirectToRoute('app_centre_settings', ['centreId' => $centre->getId()->toRfc4122()]);
    }
}
