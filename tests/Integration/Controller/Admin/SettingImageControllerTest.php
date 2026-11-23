<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Teacher;
use App\Repository\CentreSettingValueRepository;
use App\Repository\SettingDefinitionRepository;
use App\Repository\SettingFileRepository;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** The settings of type image (the centre's logo and its education authority's): upload, preview, delete and what is refused. */
final class SettingImageControllerTest extends ControllerTestCase
{
    private const string KEY = 'centre.logo';

    private EducationalCentre $centre;
    private Teacher $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $this->admin  = (new Teacher(new PersonName('Nombre', 'director')))->setUsername('director');
        $this->centre->getAdmins()->add($this->admin);
        $def = $this->em->getRepository(SettingDefinition::class)->findOneBy(['key' => self::KEY])
            ?? (new SettingDefinition())->setKey(self::KEY)->setType(SettingType::Image)->setDefaultValue('')->setCentreScope(true)->setGlobalScope(true);
        $this->persist($this->centre, $this->admin, $def);
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

    private function png(): string
    {
        $im = imagecreatetruecolor(120, 60);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 70, 96, 61));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function upload(string $content, string $name = 'logo.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'logo_');
        file_put_contents((string) $path, $content);

        return new UploadedFile((string) $path, $name, null, null, true);
    }

    private function post(string $action, string $token, ?UploadedFile $file = null): void
    {
        $this->client->request('POST', '/ajustes/imagenes/centre/' . self::KEY . '/' . $action, ['_token' => $this->csrfToken($token)], $file !== null ? ['file' => $file] : []);
    }

    public function testAnAdminUploadsViewsAndRemovesTheLogo(): void
    {
        $this->loginAs($this->admin, $this->centre);
        $this->client->request('GET', '/centro/' . $this->centre->getId()->toRfc4122() . '/ajustes');
        $this->post('subir', 'settings_image_' . self::KEY, $this->upload($this->png()));
        self::assertTrue($this->client->getResponse()->isRedirect('/centro/' . $this->centre->getId()->toRfc4122() . '/ajustes'));

        // The preview of that level and the logo the centre ends up with.
        $this->client->request('GET', '/ajustes/imagenes/centre/' . self::KEY . '/ver');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('image/png', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertStringStartsWith("\x89PNG", (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/logotipo');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $stored = self::getContainer()->get(CentreSettingValueRepository::class)->findByDefinitionAndCentre(
            self::getContainer()->get(SettingDefinitionRepository::class)->findOneBy(['key' => self::KEY]),
            $this->em->getRepository(EducationalCentre::class)->find($this->centre->getId()),
        );
        self::assertSame('logo.png', $stored?->getValue());

        $this->post('eliminar', 'settings_image_delete_' . self::KEY);
        $this->client->request('GET', '/logotipo');
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'no logo any more');
        self::assertSame(0, self::getContainer()->get(SettingFileRepository::class)->count([]), 'the orphan file is removed');
    }

    public function testOnlyPngAndJpegAreAcceptedWhateverTheNameSays(): void
    {
        $this->loginAs($this->admin, $this->centre);
        $this->client->request('GET', '/centro/' . $this->centre->getId()->toRfc4122() . '/ajustes');

        $this->post('subir', 'settings_image_' . self::KEY, $this->upload('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'logo.png'));
        $this->client->request('GET', '/logotipo');
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'an SVG with a .png name is refused');

        $this->post('subir', 'settings_image_' . self::KEY, $this->upload('GIF89a' . str_repeat("\0", 30), 'logo.png'));
        $this->client->request('GET', '/logotipo');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testATeacherWhoIsNotAnAdminCannotChangeIt(): void
    {
        $teacher = (new Teacher(new PersonName('Nombre', 'docente')))->setUsername('docente');
        $this->persist($teacher);
        $this->loginAs($teacher, $this->centre);
        $this->client->request('GET', '/');
        $this->post('subir', 'settings_image_' . self::KEY, $this->upload($this->png()));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testOnlyAPlatformAdministratorSetsThePlatformsLogo(): void
    {
        $this->loginAs($this->admin, $this->centre);
        $this->client->request('GET', '/');
        $this->client->request('POST', '/ajustes/imagenes/global/' . self::KEY . '/subir', ['_token' => $this->csrfToken('settings_image_' . self::KEY)], ['file' => $this->upload($this->png())]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'a centre director is not a platform administrator');
    }

    public function testASettingThatIsNotAnImageIsNotFound(): void
    {
        $this->loginAs($this->admin, $this->centre);
        $this->client->request('GET', '/');
        $this->client->request('GET', '/ajustes/imagenes/centre/page.size/ver');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }
}
