<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Entity\SchoolEvent;
use App\Entity\SchoolEventProfile;
use App\Model\ProfileAssignmentRow;
use App\Repository\SchoolEventRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ProfileAssignmentRowBuilder;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/eventos')]
class SchoolEventController extends AbstractController
{
    use PastYearGuardTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly SchoolEventRepository $events,
        private readonly ProfileAssignmentRowBuilder $rowBuilder,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/nuevo', name: 'app_events_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);
        $this->denyIfViewingPastYear($centre);

        $year = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        $errors = [];
        $values = $this->emptyValues();
        $values['date'] = trim($request->query->getString('date'));
        $selectedProfileKeys = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_school_event', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values              = $this->valuesFromRequest($request);
            $selectedProfileKeys = $request->request->all('profiles');
            $errors              = $this->validate($values, $selectedProfileKeys);

            if (empty($errors)) {
                $event = (new SchoolEvent())->setAcademicYear($year);
                $this->applyValues($event, $values, $centre, $selectedProfileKeys);

                $this->em->persist($event);
                $this->em->flush();

                $this->addFlash('success', $this->t('school_event.flash.created'));

                return $this->redirectToRoute('app_calendar', ['tab' => 'events']);
            }
        }

        return $this->render('school_event/new.html.twig', [
            'centre'               => $centre,
            'errors'               => $errors,
            'values'               => $values,
            'availableProfileRows' => $this->rowBuilder->buildActiveRows($centre),
            'selectedProfileKeys'  => $selectedProfileKeys,
        ]);
    }

    #[Route('/{eventId}/editar', name: 'app_events_edit', methods: ['GET', 'POST'])]
    public function edit(string $eventId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);
        $this->denyIfViewingPastYear($centre);

        $year = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        $event = $this->events->findByAcademicYearAndId($year, $eventId);
        if ($event === null) {
            throw $this->createNotFoundException();
        }

        $errors = [];
        $values = [
            'date'        => $event->getDate()->format('Y-m-d'),
            'start_time'  => $event->getStartTime()->format('H:i'),
            'end_time'    => $event->getEndTime()->format('H:i'),
            'name'        => $event->getName(),
            'description' => $event->getDescription() ?? '',
            'url'         => $event->getUrl() ?? '',
            'scope'       => $event->isGeneral() ? 'general' : 'restricted',
        ];
        $selectedProfileKeys = array_map(
            static fn (SchoolEventProfile $r): string => ProfileAssignmentRow::keyFor($r->getSpecificProfile(), $r->getListItem()),
            $event->getProfileRestrictions()->toArray(),
        );

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_school_event_' . $eventId, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values              = $this->valuesFromRequest($request);
            $selectedProfileKeys = $request->request->all('profiles');
            $errors              = $this->validate($values, $selectedProfileKeys);

            if (empty($errors)) {
                $this->applyValues($event, $values, $centre, $selectedProfileKeys);
                $this->em->flush();

                $this->addFlash('success', $this->t('school_event.flash.saved'));

                return $this->redirectToRoute('app_calendar', ['tab' => 'events']);
            }
        }

        return $this->render('school_event/edit.html.twig', [
            'centre'               => $centre,
            'event'                => $event,
            'errors'               => $errors,
            'values'               => $values,
            'availableProfileRows' => $this->rowBuilder->buildActiveRows($centre),
            'selectedProfileKeys'  => $selectedProfileKeys,
        ]);
    }

    #[Route('/{eventId}/eliminar', name: 'app_events_delete', methods: ['POST'])]
    public function delete(string $eventId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);
        $this->denyIfViewingPastYear($centre);

        $year = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        $event = $this->events->findByAcademicYearAndId($year, $eventId);
        if ($event === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('delete_school_event_' . $eventId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->em->remove($event);
        $this->em->flush();

        $this->addFlash('success', $this->t('school_event.flash.deleted'));

        return $this->redirectToRoute('app_calendar', ['tab' => 'events']);
    }

    /** @return array<string, string> */
    private function emptyValues(): array
    {
        return [
            'date'        => '',
            'start_time'  => '',
            'end_time'    => '',
            'name'        => '',
            'description' => '',
            'url'         => '',
            'scope'       => '',
        ];
    }

    /** @return array<string, string> */
    private function valuesFromRequest(Request $request): array
    {
        return [
            'date'        => trim($request->request->getString('date')),
            'start_time'  => trim($request->request->getString('start_time')),
            'end_time'    => trim($request->request->getString('end_time')),
            'name'        => trim($request->request->getString('name')),
            'description' => trim($request->request->getString('description')),
            'url'         => trim($request->request->getString('url')),
            'scope'       => trim($request->request->getString('scope')),
        ];
    }

    /**
     * @param array<string, string>   $values
     * @param array<array-key, mixed> $selectedProfileKeys
     *
     * @return array<string, string>
     */
    private function validate(array $values, array $selectedProfileKeys): array
    {
        $errors = [];

        if ($values['name'] === '') {
            $errors['name'] = $this->t('school_event.error.name_required');
        }

        if ($this->parseDate($values['date']) === null) {
            $errors['date'] = $this->t('school_event.error.date_required');
        }

        $start = $this->parseTime($values['start_time']);
        $end   = $this->parseTime($values['end_time']);
        if ($start === null || $end === null) {
            $errors['time'] = $this->t('school_event.error.time_required');
        } elseif ($start >= $end) {
            $errors['time'] = $this->t('school_event.error.time_invalid');
        }

        if (!in_array($values['scope'], ['general', 'restricted'], true)) {
            $errors['scope'] = $this->t('school_event.error.scope_required');
        } elseif ($values['scope'] === 'restricted' && $selectedProfileKeys === []) {
            $errors['scope'] = $this->t('school_event.error.profiles_required');
        }

        if ($values['url'] !== '' && filter_var($values['url'], FILTER_VALIDATE_URL) === false) {
            $errors['url'] = $this->t('school_event.error.url_invalid');
        }

        return $errors;
    }

    /**
     * @param array<string, string>   $values
     * @param array<array-key, mixed> $selectedProfileKeys
     */
    private function applyValues(SchoolEvent $event, array $values, EducationalCentre $centre, array $selectedProfileKeys): void
    {
        $general = $values['scope'] === 'general';

        $event->setDate($this->parseDate($values['date']) ?? $event->getDate())
            ->setStartTime($this->parseTime($values['start_time']) ?? $event->getStartTime())
            ->setEndTime($this->parseTime($values['end_time']) ?? $event->getEndTime())
            ->setName($values['name'])
            ->setDescription($values['description'] !== '' ? $values['description'] : null)
            ->setUrl($values['url'] !== '' ? $values['url'] : null)
            ->setGeneral($general);

        foreach ($event->getProfileRestrictions()->toArray() as $restriction) {
            $event->removeProfileRestriction($restriction);
        }

        if (!$general) {
            $rowsByKey = [];
            foreach ($this->rowBuilder->buildActiveRows($centre) as $row) {
                $rowsByKey[$row->key()] = $row;
            }

            foreach ($selectedProfileKeys as $key) {
                if (is_string($key) && isset($rowsByKey[$key])) {
                    $event->addProfileRestriction($rowsByKey[$key]->profile, $rowsByKey[$key]->listItem);
                }
            }
        }
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false ? $date : null;
    }

    private function parseTime(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $time = \DateTimeImmutable::createFromFormat('H:i', $value);

        return $time !== false ? $time : null;
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }
}
