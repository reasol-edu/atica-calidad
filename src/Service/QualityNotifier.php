<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\ImprovementAction;
use App\Entity\Teacher;
use App\Repository\SpecificProfileAssignmentRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The "Mejora continua" emails: each one tells someone that it's their turn (or how what they
 * reported ended), with a link to the finding. Sent through NotificationMailer — so the teacher's
 * own "email notifications" setting and the email log apply — and only to whoever has
 * notifications.quality_notifications_enabled on (global, centre or personal setting).
 */
final class QualityNotifier
{
    public function __construct(
        private readonly NotificationMailer $mailer,
        private readonly AppSettingsInterface $settings,
        private readonly SpecificProfileAssignmentRepository $assignments,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urls,
        private readonly Environment $twig,
    ) {}

    /** To the quality managers: something new to classify. */
    public function reported(Finding $finding): void
    {
        $this->send($this->qualityManagers($finding->getEducationalCentre()), $finding, 'reported', $finding->getDescription());
    }

    /** To the analysis responsible: a nonconformity to analyse. */
    public function analysisAssigned(Finding $finding): void
    {
        $this->send(array_filter([$finding->getAnalysisResponsible()]), $finding, 'analysis_assigned', null, [
            '%date%' => $finding->getAnalysisDueDate()?->format('d/m/Y') ?? '—',
        ]);
    }

    /**
     * To whoever has to carry out the action (its teacher, or everyone holding its profile) — about
     * its finding, or for a plan action, about the action itself.
     */
    public function actionAssigned(ImprovementAction $action): void
    {
        $recipients = $action->getResponsibleTeacher() !== null
            ? [$action->getResponsibleTeacher()]
            : ($action->getResponsibleProfile() === null ? [] : $this->assignments->findTeachersHoldingProfileAndListItem($action->getResponsibleProfile(), null));
        $params = ['%date%' => $action->getDueDate()?->format('d/m/Y') ?? '—'];

        $finding = $action->getFinding();
        if ($finding !== null) {
            $this->send($recipients, $finding, 'action_assigned', $action->getDescription(), $params);

            return;
        }

        $this->deliver($recipients, $action->getEducationalCentre(), 'plan_action_assigned', [
            'code'    => $action->getCode() ?? '',
            'title'   => $action->getDescription(),
            'section' => $action->getSection()?->getName(),
            'url'     => $this->urls->generate('app_quality_action', ['id' => $action->getId()->toRfc4122()], UrlGeneratorInterface::ABSOLUTE_URL),
            'cta'     => $this->translator->trans('email.cta_action', [], 'quality'),
        ], $action->getGoal(), $params + ['%code%' => $action->getCode() ?? '']);
    }

    /** To the quality managers: every action done, time to check whether it worked. */
    public function verificationRequested(Finding $finding): void
    {
        $this->send($this->qualityManagers($finding->getEducationalCentre()), $finding, 'verification_requested', null, [
            '%date%' => $finding->getVerificationDueDate()?->format('d/m/Y') ?? '—',
        ]);
    }

    /** To the analysis responsible: the actions didn't work, back to analysing. */
    public function verifiedIneffective(Finding $finding): void
    {
        $this->send(array_filter([$finding->getAnalysisResponsible()]), $finding, 'verified_ineffective', $finding->getVerificationNotes());
    }

    /** To whoever reported it: discarded, and why. */
    public function discarded(Finding $finding): void
    {
        $this->send(array_filter([$finding->getReportedBy()]), $finding, 'discarded', $finding->getDiscardReason());
    }

    /** To whoever reported it: solved. */
    public function closed(Finding $finding): void
    {
        $this->send(array_filter([$finding->getReportedBy()]), $finding, 'closed', null);
    }

    /**
     * The centre's quality managers, or its admins when it has none.
     *
     * @return list<Teacher>
     */
    public function qualityManagers(EducationalCentre $centre): array
    {
        $managers = $centre->getQualityManagers()->toArray();

        return array_values($managers !== [] ? $managers : $centre->getAdmins()->toArray());
    }

    /**
     * @param iterable<Teacher>     $recipients
     * @param array<string, string> $params
     */
    private function send(iterable $recipients, Finding $finding, string $event, ?string $quote, array $params = []): void
    {
        $code    = $finding->getCode() ?? $this->translator->trans('finding.no_code', [], 'quality');
        $params += ['%code%' => $code, '%title%' => $finding->getTitle()];

        $this->deliver($recipients, $finding->getEducationalCentre(), $event, [
            'code'    => $code,
            'title'   => $finding->getTitle(),
            'section' => $finding->getSection()?->getName(),
            'url'     => $this->urls->generate('app_quality_finding', ['id' => $finding->getId()->toRfc4122()], UrlGeneratorInterface::ABSOLUTE_URL),
            'cta'     => $this->translator->trans('email.cta', [], 'quality'),
        ], $quote, $params);
    }

    /**
     * @param iterable<Teacher>                                                              $recipients
     * @param array{code: string, title: string, section: ?string, url: string, cta: string} $about      what the email is about, and the link to it
     * @param array<string, string>                                                          $params
     */
    private function deliver(iterable $recipients, EducationalCentre $centre, string $event, array $about, ?string $quote, array $params): void
    {
        $sent = [];
        foreach ($recipients as $teacher) {
            $key = $teacher->getId()->toRfc4122();
            if (isset($sent[$key]) || !$teacher->isActive() || $teacher->getEmail() === null
                || $this->settings->getForTeacherInCentre('notifications.quality_notifications_enabled', $teacher, $centre) === false) {
                continue;
            }
            $sent[$key] = true;

            $this->mailer->send(
                $teacher,
                $centre,
                'quality_' . $event,
                $this->translator->trans('email.' . $event . '.subject', $params, 'quality'),
                $this->translator->trans('email.' . $event . '.heading', $params, 'quality'),
                $this->twig->render('email/_quality_body.html.twig', [
                    'intro' => $this->translator->trans('email.' . $event . '.intro', $params, 'quality'),
                    'about' => $about,
                    'quote' => $quote,
                ]),
                $about['url'],
                $about['cta'],
            );
        }
    }
}
