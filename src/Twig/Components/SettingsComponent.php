<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\SettingDefinition;
use App\Entity\Teacher;
use App\Service\AppSettings;
use App\Service\SettingsManager;
use App\Service\SettingsSaveOutcome;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class SettingsComponent extends AbstractController
{
    use DefaultActionTrait;

    /** 'global' | 'centre' | 'teacher' */
    #[LiveProp]
    public string $scope = 'teacher';

    /** Key of the last saved/reset setting — used for inline feedback. */
    #[LiveProp]
    public string $lastSaved = '';

    /** Key of the setting that last failed validation — used for inline error feedback. */
    #[LiveProp]
    public string $lastError = '';

    public function __construct(
        private readonly SettingsManager $settingsManager,
        private readonly TenantContext $tenant,
        private readonly Security $security,
        private readonly AppSettings $appSettings,
    ) {}

    /**
     * Returns rows for the current scope, each row containing the definition,
     * the stored raw value (null = not set), the stored file (pdf-typed
     * settings only), lock state and parent-lock origin.
     *
     * @return list<array{definition: SettingDefinition, storedValue: ?string, storedFile: ?\App\Entity\SettingFile, effectiveValue: string, isLocked: bool, parentLock: ?string}>
     */
    public function getRows(): array
    {
        return $this->settingsManager->getRows($this->scope, $this->centreForScope(), $this->teacherForScope());
    }

    /**
     * Saves or resets a setting value for the current scope.
     * An empty string or '__default__' removes the stored value.
     */
    #[LiveAction]
    public function save(#[LiveArg] string $key, #[LiveArg] string $value): void
    {
        $outcome = $this->settingsManager->save(
            $this->scope,
            $key,
            $value,
            $this->centreForScope(),
            $this->teacherForScope(),
        );

        match ($outcome) {
            SettingsSaveOutcome::Saved => $this->onSaved($key),
            SettingsSaveOutcome::RejectedInvalid => $this->lastError = $key,
            SettingsSaveOutcome::RejectedLocked => null,
        };
    }

    #[LiveAction]
    public function toggleLock(#[LiveArg] string $key): void
    {
        $this->settingsManager->toggleLock($this->scope, $key, $this->centreForScope());
        $this->appSettings->invalidate();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function onSaved(string $key): void
    {
        $this->lastError = '';
        $this->lastSaved = $key;
        $this->appSettings->invalidate();
    }

    /** Centre is required for the 'centre' scope; used opportunistically (for lock inheritance) in 'teacher' scope. */
    private function centreForScope(): ?EducationalCentre
    {
        $centre = $this->tenant->getSelectedCentre();
        if ($this->scope === 'centre' && $centre === null) {
            throw $this->createAccessDeniedException();
        }

        return $centre;
    }

    private function teacherForScope(): ?Teacher
    {
        if ($this->scope !== 'teacher') {
            return null;
        }

        $user = $this->security->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
