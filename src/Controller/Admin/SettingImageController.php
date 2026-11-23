<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentCentre;
use App\Controller\TranslatorTrait;
use App\Entity\CentreSettingValue;
use App\Entity\EducationalCentre;
use App\Entity\GlobalSettingValue;
use App\Entity\SettingDefinition;
use App\Entity\SettingFile;
use App\Entity\SettingType;
use App\Repository\CentreSettingValueRepository;
use App\Repository\GlobalSettingValueRepository;
use App\Repository\SettingDefinitionRepository;
use App\Repository\SettingFileRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\AppSettings;
use App\Service\SettingFileGarbageCollector;
use App\Service\SettingsManager;
use App\Service\TenantContext;
use App\Service\UploadLimits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Settings of type image (the centre's logo): upload, delete and show the image of one level (`global`, for the platform's
 * administrators, or `centre`), and the logo the current centre ends up with (its own, else the platform's). Only PNG and JPEG
 * are accepted — recognised by their content, not by what the browser says — and they are served with a content type that is
 * never sniffed into something else (no SVG: it can carry scripts).
 */
class SettingImageController extends AbstractController
{
    use TranslatorTrait;

    public const int MAX_BYTES = 2 * 1024 * 1024;
    public const int MAX_SIDE  = 4000;

    private const array TYPES = ['image/png', 'image/jpeg'];

    /** The two logos of the documents: the centre's ({logo}) and its education authority's ({logo2}), by the number the route gives. */
    public const array LOGOS = ['1' => 'centre.logo', '2' => 'centre.education_authority_logo'];

    public function __construct(
        protected readonly TranslatorInterface $translator,
        private readonly SettingDefinitionRepository $definitions,
        private readonly GlobalSettingValueRepository $globalValues,
        private readonly CentreSettingValueRepository $centreValues,
        private readonly SettingFileRepository $files,
        private readonly SettingFileGarbageCollector $garbageCollector,
        private readonly SettingsManager $settings,
        private readonly AppSettings $appSettings,
        private readonly EntityManagerInterface $em,
        private readonly UploadLimits $uploadLimits,
        private readonly TenantContext $tenant,
    ) {}

    #[Route('/ajustes/imagenes/{scope}/{key}/subir', name: 'app_settings_image_upload', requirements: ['scope' => 'global|centre'], methods: ['POST'])]
    public function upload(string $scope, string $key, Request $request): Response
    {
        $centre     = $this->centreFor($scope);
        $definition = $this->authorise($scope, $key, $centre);
        if (!$this->isCsrfTokenValid('settings_image_' . $key, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($this->settings->isLockedByParent($scope, $key, $centre)) {
            throw $this->createAccessDeniedException();
        }

        $file    = $request->files->get('file');
        $problem = $this->uploadLimits->fileProblem($file);
        if ($problem !== null) {
            $this->addFlash('error', $problem);

            return $this->back($centre);
        }
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t('settings.image.error.no_file'));

            return $this->back($centre);
        }

        $content = (string) file_get_contents($file->getPathname());
        $error   = $this->problemWith($content);
        if ($error !== null) {
            $this->addFlash('error', $this->t($error));

            return $this->back($centre);
        }

        $this->store($definition, $centre, $content, $file->getClientOriginalName());
        $this->addFlash('success', $this->t('settings.image.flash.uploaded'));

        return $this->back($centre);
    }

    #[Route('/ajustes/imagenes/{scope}/{key}/eliminar', name: 'app_settings_image_delete', requirements: ['scope' => 'global|centre'], methods: ['POST'])]
    public function delete(string $scope, string $key, Request $request): Response
    {
        $centre     = $this->centreFor($scope);
        $definition = $this->authorise($scope, $key, $centre);
        if (!$this->isCsrfTokenValid('settings_image_delete_' . $key, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($this->settings->isLockedByParent($scope, $key, $centre)) {
            throw $this->createAccessDeniedException();
        }

        $value = $centre === null ? $this->globalValues->findByDefinition($definition) : $this->centreValues->findByDefinitionAndCentre($definition, $centre);
        if ($value !== null) {
            $file = $value->getFile();
            $this->em->remove($value);
            $this->em->flush();
            if ($file !== null) {
                $this->garbageCollector->deleteIfOrphaned($file);
            }
            $this->appSettings->invalidate();
            $this->addFlash('success', $this->t('settings.image.flash.deleted'));
        }

        return $this->back($centre);
    }

    /** The image stored at one level (for the preview next to the setting). */
    #[Route('/ajustes/imagenes/{scope}/{key}/ver', name: 'app_settings_image_view', requirements: ['scope' => 'global|centre'], methods: ['GET'])]
    public function view(string $scope, string $key): Response
    {
        $centre     = $this->centreFor($scope);
        $definition = $this->authorise($scope, $key, $centre);
        $value      = $centre === null ? $this->globalValues->findByDefinition($definition) : $this->centreValues->findByDefinitionAndCentre($definition, $centre);
        $file       = $value?->getFile();
        if ($file === null) {
            throw $this->createNotFoundException();
        }

        return $this->image($file);
    }

    /** The logo the current centre ends up with: its own, else the platform's. Any signed-in person of the centre may see it (it is in every document). */
    #[Route('/logotipo/{slot}', name: 'app_centre_logo', requirements: ['slot' => '1|2'], defaults: ['slot' => '1'], methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function logo(string $slot, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $resolved = $this->appSettings->getFileForCentre(self::LOGOS[$slot], $centre);
        if ($resolved === null) {
            throw $this->createNotFoundException();
        }

        return $this->image($resolved->file);
    }

    private function image(SettingFile $file): Response
    {
        $response = new Response($file->getContent());
        $response->headers->set('Content-Type', $file->getMimeType());
        $response->headers->set('Content-Disposition', 'inline');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        // The setting changes rarely and the content is identified by its hash: the browser may keep it a little.
        $response->setPrivate();
        $response->setMaxAge(60);
        $response->setEtag($file->getHash());

        return $response;
    }

    /** The selected centre for the `centre` level; none for the platform's (an administrator needs no centre chosen). */
    private function centreFor(string $scope): ?EducationalCentre
    {
        if ($scope === 'global') {
            return null;
        }

        return $this->tenant->getSelectedCentre() ?? throw $this->createAccessDeniedException();
    }

    private function authorise(string $scope, string $key, ?EducationalCentre $centre): SettingDefinition
    {
        if ($centre === null) {
            $this->denyAccessUnlessGranted('ROLE_ADMIN');
        } else {
            $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);
        }
        $definition = $this->definitions->findOneBy(['key' => $key]);
        if ($definition === null || $definition->getType() !== SettingType::Image) {
            throw $this->createNotFoundException();
        }

        return $definition;
    }

    /** @return ?string the translation key of what is wrong with the image */
    private function problemWith(string $content): ?string
    {
        if (strlen($content) > self::MAX_BYTES) {
            return 'settings.image.error.too_large';
        }
        $info = @getimagesizefromstring($content);
        if ($info === false || !in_array($info['mime'], self::TYPES, true)) {
            return 'settings.image.error.invalid_type';
        }
        if ($info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE) {
            return 'settings.image.error.too_big_side';
        }

        return null;
    }

    private function store(SettingDefinition $definition, ?EducationalCentre $centre, string $content, string $filename): void
    {
        $info        = getimagesizefromstring($content);
        $mime        = $info === false ? 'image/png' : $info['mime'];
        $hash        = hash('sha256', $content);
        $settingFile = $this->files->findByHash($hash);
        if ($settingFile === null) {
            $settingFile = new SettingFile($hash, $content, $mime, strlen($content));
            $this->em->persist($settingFile);
        }

        $name  = mb_substr($filename, 0, 200);
        $value = $centre === null ? $this->globalValues->findByDefinition($definition) : $this->centreValues->findByDefinitionAndCentre($definition, $centre);
        $old   = $value?->getFile();
        if ($value === null) {
            $value = $centre === null
                ? (new GlobalSettingValue())->setDefinition($definition)
                : (new CentreSettingValue())->setDefinition($definition)->setCentre($centre);
        }
        $value->setValue($name)->setFile($settingFile);
        $this->em->persist($value);
        $this->em->flush();

        if ($old !== null && $old !== $settingFile) {
            $this->garbageCollector->deleteIfOrphaned($old);
        }
        $this->appSettings->invalidate();
    }

    private function back(?EducationalCentre $centre): Response
    {
        return $centre === null
            ? $this->redirectToRoute('app_admin_settings')
            : $this->redirectToRoute('app_centre_settings', ['centreId' => $centre->getId()->toRfc4122()]);
    }

    private function translationDomain(): string
    {
        return 'settings';
    }
}
