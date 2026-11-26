<?php

declare(strict_types=1);

namespace App\Twig\Components\Concerns;

use App\Entity\Activity;
use App\Entity\ActivityProfile;
use App\Entity\ActivityResponsibleProfile;
use App\Entity\ActivitySubmissionScope;
use App\Entity\Document;
use App\Entity\Folder;
use App\Entity\ListItem;
use App\Model\ProfileAssignmentRow;
use App\Security\Voter\EducationalCentreVoter;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;

/**
 * Activity add/edit form actions: start, duplicate, save and delete an activity.
 */
trait ActivityFormActions
{
    #[LiveAction]
    public function startAddActivity(): void
    {
        $this->requireEditPermission();
        $this->formActivityId  = '';
        $this->formTitle       = '';
        $this->formDescription = '';
        $this->formSubmissionPrefix = '';
        $this->formStartDay    = '';
        $this->formStartMonth  = '';
        $this->formEndDay      = '';
        $this->formEndMonth    = '';
        $this->formFolderId    = '';
        $this->loadFolderProfileKeys(null);
        $this->formWithSubmissions  = false;
        $this->advancedOpen         = false;
        $this->folderProfilesNotice = '';
        $this->creatingFolder       = false;
        $this->newFolderName       = '';
        $this->newFolderSectionId  = '';
        $this->formListItemId  = '';
        $this->formOverrideStartDay   = [];
        $this->formOverrideStartMonth = [];
        $this->formOverrideEndDay     = [];
        $this->formOverrideEndMonth   = [];
        $this->formRelatedDocumentIds     = [];
        $this->relatedDocumentSearchQuery = '';
        $this->formRequired    = true;
        $this->formAutoComplete = false;
        $this->formStartDateEnforced = false;
        $this->formEndDateEnforced   = false;
        $this->formEndDateGraceDays  = '0';
        $this->formScope       = 'by_profile';
        $this->formModelId     = '';
        $this->formDraftsForAll = false;
        $this->formNameFullPath = true;
        $this->formTeachersDeliver = false;
        $this->formDimensionIds     = [];
        $this->formDatedDimensionId = '';
        $this->formSplitByElement   = true;
        $this->formDimensionToAdd   = '';
        $this->formPrefillSourceId = '';
        $this->formDeliverySources = [];
        $this->formHidden      = false;
        $this->formGeneral     = true;
        $this->formProfileKeys = [];
        $this->formResponsibleProfileKeys = [];
        $this->activityFormOpen = true;
        $this->errors           = [];
    }

    #[LiveAction]
    public function startEditActivity(#[LiveArg] string $id): void
    {
        $this->requireEditPermission();
        $activity = $this->findActivity($id);
        if ($activity === null) {
            return;
        }

        $this->formActivityId   = $id;
        $this->formTitle        = $activity->getTitle();
        $this->formDescription  = $activity->getDescription() ?? '';
        $this->formSubmissionPrefix = $activity->getSubmissionPrefix() ?? '';
        $this->formStartDay     = (string) $activity->getStartDay();
        $this->formStartMonth   = (string) $activity->getStartMonth();
        $this->formEndDay       = (string) $activity->getEndDay();
        $this->formEndMonth     = (string) $activity->getEndMonth();
        $this->formFolderId     = $activity->getFolder()?->getId()->toRfc4122() ?? '';
        $this->loadFolderProfileKeys($activity->getFolder());
        $this->formWithSubmissions  = $activity->getFolder() !== null;
        $this->advancedOpen         = $activity->isHidden() || $activity->isAutoComplete();
        $this->folderProfilesNotice = '';
        $this->creatingFolder      = false;
        $this->newFolderName      = '';
        $this->newFolderSectionId = '';
        $this->formListItemId   = $activity->getListItem()?->getId()->toRfc4122() ?? '';
        $this->formDimensionIds     = array_map(static fn (\App\Entity\ActivityDimension $d): string => $d->getList()->getId()->toRfc4122(), $activity->getDimensions());
        $this->formDatedDimensionId = $activity->getDatedDimension()?->getList()->getId()->toRfc4122() ?? '';
        $this->formSplitByElement   = $activity->isSplitByElement();
        $this->formDimensionToAdd   = '';
        $this->formOverrideStartDay   = [];
        $this->formOverrideStartMonth = [];
        $this->formOverrideEndDay     = [];
        $this->formOverrideEndMonth   = [];
        foreach ($activity->getListItemDeadlines() as $override) {
            $leafId = $override->getListItem()->getId()->toRfc4122();
            $this->formOverrideStartDay[$leafId]   = (string) $override->getStartDay();
            $this->formOverrideStartMonth[$leafId] = (string) $override->getStartMonth();
            $this->formOverrideEndDay[$leafId]     = (string) $override->getEndDay();
            $this->formOverrideEndMonth[$leafId]   = (string) $override->getEndMonth();
        }
        $this->seedOverrideArraysForCurrentListItem();
        $this->formRelatedDocumentIds     = array_map(static fn (Document $d): string => $d->getId()->toRfc4122(), $activity->getRelatedDocuments()->toArray());
        $this->relatedDocumentSearchQuery = '';
        $this->formRequired     = $activity->isRequired();
        $this->formAutoComplete = $activity->isAutoComplete();
        $this->formStartDateEnforced = $activity->isStartDateEnforced();
        $this->formEndDateEnforced   = $activity->isEndDateEnforced();
        $this->formEndDateGraceDays  = (string) $activity->getEndDateGraceDays();
        $this->formScope        = $activity->getSubmissionScope()->value;
        $this->formModelId      = $activity->getFormModel()?->getId()->toRfc4122() ?? '';
        $this->formDraftsForAll = $activity->isFormDraftsForAll();
        $this->formNameFullPath = $activity->isSubmissionNameFullPath();
        $this->formTeachersDeliver = $activity->isTeachersDeliver();
        $this->formPrefillSourceId = $activity->getFormPrefillSource()?->getId()->toRfc4122() ?? '';
        $this->formDeliverySources = array_map(static fn (Activity $a): string => $a->getId()->toRfc4122(), $activity->getDeliverySources());
        $this->formHidden       = $activity->isHidden();
        $this->formGeneral      = $activity->isGeneral();
        $this->formProfileKeys  = array_map(
            static fn (ActivityProfile $r): string => ProfileAssignmentRow::keyFor($r->getSpecificProfile(), $r->getListItem()),
            $activity->getProfileRestrictions()->toArray(),
        );
        $this->formResponsibleProfileKeys = array_map(
            static fn (ActivityResponsibleProfile $r): string => ProfileAssignmentRow::keyFor($r->getSpecificProfile(), $r->getListItem()),
            $activity->getResponsibleProfiles()->toArray(),
        );
        $this->activityFormOpen = true;
        $this->errors           = [];
    }

    #[LiveAction]
    public function toggleAdvanced(): void
    {
        $this->advancedOpen = !$this->advancedOpen;
    }

    /** Copies the activity's general deadline onto every element of the chosen list, replacing what they had. */
    #[LiveAction]
    public function applyGeneralDeadlineToElements(): void
    {
        foreach ($this->getFormListItemLeaves() as $leaf) {
            $leafId = $leaf->getId()->toRfc4122();
            $this->formOverrideStartDay[$leafId]   = $this->formStartDay;
            $this->formOverrideStartMonth[$leafId] = $this->formStartMonth;
            $this->formOverrideEndDay[$leafId]     = $this->formEndDay;
            $this->formOverrideEndMonth[$leafId]   = $this->formEndMonth;
        }
    }

    /** Back to the general deadline for every element of the list. */
    #[LiveAction]
    public function clearElementDeadlines(): void
    {
        $this->formOverrideStartDay = $this->formOverrideStartMonth = $this->formOverrideEndDay = $this->formOverrideEndMonth = [];
        $this->seedOverrideArraysForCurrentListItem();
    }

    /**
     * A copy of the activity in the same category, hidden until it's been reviewed and with no
     * folder (a folder belongs to one activity), opened in the form so what changes can be set
     * right away — activities repeat from year to year with small variations.
     */
    #[LiveAction]
    public function duplicateActivity(#[LiveArg] string $id): void
    {
        $this->requireEditPermission();
        $source = $this->findActivity($id) ?? throw $this->createNotFoundException();

        $category = $source->getCategory();
        $copy     = (new Activity())
            ->setCategory($category)
            ->setPosition($this->activities->nextPosition($category))
            ->setTitle($this->translator->trans('activity.duplicate.title', ['%title%' => $source->getTitle()], 'admin'))
            ->setDescription($source->getDescription())
            ->setSubmissionPrefix($source->getSubmissionPrefix())
            ->setSubmissionNameFullPath($source->isSubmissionNameFullPath())
            ->setTeachersDeliver($source->isTeachersDeliver())
            ->setStart($source->getStartDay(), $source->getStartMonth())
            ->setEnd($source->getEndDay(), $source->getEndMonth())
            ->setListItem($source->getListItem())
            ->setRequired($source->isRequired())
            ->setSubmissionScope($source->getSubmissionScope())
            ->setStartDateEnforced($source->isStartDateEnforced())
            ->setEndDateEnforced($source->isEndDateEnforced())
            ->setEndDateGraceDays($source->getEndDateGraceDays())
            ->setGeneral($source->isGeneral())
            ->setSplitByElement($source->isSplitByElement())
            ->setHidden(true);
        // «Una entrega por» and the dates of each value (the overrides below include them).
        $dimensions = $source->getDimensions();
        $dated      = null;
        foreach ($dimensions as $index => $dimension) {
            $dated = $dimension->isDated() ? $index : $dated;
        }
        $copy->setDimensions(array_map(static fn (\App\Entity\ActivityDimension $d): ListItem => $d->getList(), $dimensions), $dated);
        foreach ($source->getListItemDeadlines() as $override) {
            $copy->setDeadlineOverride($override->getListItem(), $override->getStartDay(), $override->getStartMonth(), $override->getEndDay(), $override->getEndMonth());
        }
        foreach ($source->getRelatedDocuments() as $document) {
            $copy->addRelatedDocument($document);
        }
        foreach ($source->getProfileRestrictions() as $restriction) {
            $copy->addProfileRestriction($restriction->getSpecificProfile(), $restriction->getListItem());
        }
        foreach ($source->getResponsibleProfiles() as $responsible) {
            $copy->addResponsibleProfile($responsible->getSpecificProfile(), $responsible->getListItem());
        }
        // Where the rows of its tables of deliveries come from (they apply once the copy is given the same model).
        foreach ($source->getDeliverySources() as $blockKey => $deliveries) {
            $copy->setDeliverySource($blockKey, $deliveries);
        }
        $this->em->persist($copy);
        $this->changeRecorder->recordDuplicated($copy, $source, $this->teacher());
        $this->em->flush();

        $this->flashSuccess($this->translator->trans('activity.flash.duplicated', [], 'admin'));
        $this->startEditActivity($copy->getId()->toRfc4122());
    }

    #[LiveAction]
    public function cancelActivityForm(): void
    {
        $this->activityFormOpen = false;
        $this->errors           = [];
    }

    /**
     * Opens the inline "create a new folder" panel in place of the existing-folder picker — lets
     * an activity be given a brand-new folder without leaving the activity form. Mirrors
     * SectionBrowserComponent::addFolder(): just name + document section, the same minimal set a
     * folder needs to exist; everything else (upload/visibility/review profiles, allowed formats…)
     * is configured afterwards from the document tree, same as for any other folder.
     */
    #[LiveAction]
    public function startCreateFolder(): void
    {
        $this->requireEditPermission();
        $this->creatingFolder      = true;
        $this->newFolderName      = '';
        $this->newFolderSectionId = '';
    }

    #[LiveAction]
    public function cancelCreateFolder(): void
    {
        $this->creatingFolder = false;
        $this->errors         = [];
    }

    #[LiveAction]
    public function createFolderForActivity(): void
    {
        $this->requireEditPermission();
        $name    = trim($this->newFolderName);
        $section = $this->newFolderSectionId === '' ? null : $this->sections->findByIdAndCentre($this->newFolderSectionId, $this->centre);
        if ($name === '' || $section === null) {
            $this->errors = ['newFolder' => $this->t('activity.error.folder_name_and_section_required')];

            return;
        }

        $folder = (new Folder())
            ->setDocumentSection($section)
            ->setName($name)
            ->setPosition($this->folders->nextPosition($section));
        $this->em->persist($folder);
        $this->em->flush();

        // Immediately picked as the activity's own folder — getAvailableFolders() already
        // includes it (its activity is still null), so the picker just renders it selected.
        $this->formFolderId   = $folder->getId()->toRfc4122();
        $this->loadFolderProfileKeys($folder);
        $this->formWithSubmissions  = true;
        $this->folderProfilesNotice = '';
        $this->creatingFolder = false;
        $this->errors         = [];
    }

    #[LiveAction]
    public function saveActivity(): void
    {
        $this->requireEditPermission();
        $category = $this->getCurrentCategory();
        if ($category === null) {
            return;
        }
        ++$this->saveAttempt;

        $title = trim($this->formTitle);
        if ($title === '') {
            $this->errors = ['title' => $this->t('activity.error.name_required')];

            return;
        }

        $listItem = $this->formListItemId === '' ? null : $this->listItems->findByIdAndCentre($this->formListItemId, $this->centre);
        // The rows with their own dates: the elements', or — with a list that marks the dates — its values'.
        $leaves   = $this->getFormListItemLeaves();
        $overridesByLeafId = $this->readDeadlineOverrides($leaves);
        if ($overridesByLeafId === false) {
            $this->errors = ['dates' => $this->t('activity.error.invalid_date')];

            return;
        }

        $startDay   = (int) $this->formStartDay;
        $startMonth = (int) $this->formStartMonth;
        $endDay     = (int) $this->formEndDay;
        $endMonth   = (int) $this->formEndMonth;
        $generalBlank = $this->formStartDay === '' && $this->formStartMonth === '' && $this->formEndDay === '' && $this->formEndMonth === '';
        if ($generalBlank) {
            // No general deadline is needed when every element of the list has its own: the
            // activity's own dates then just span theirs (they're what calendars etc. fall back on).
            if ($leaves === [] || count($overridesByLeafId) !== count($leaves)) {
                $this->errors = ['dates' => $this->t('activity.error.dates_required_unless_all_elements')];

                return;
            }
            $envelope = $this->deadline->envelopeOf($this->centre, array_values($overridesByLeafId));
            ['startDay' => $startDay, 'startMonth' => $startMonth, 'endDay' => $endDay, 'endMonth' => $endMonth] = $envelope;
        } elseif ($startDay < 1 || $startDay > 31 || $startMonth < 1 || $startMonth > 12 || $endDay < 1 || $endDay > 31 || $endMonth < 1 || $endMonth > 12) {
            $this->errors = ['dates' => $this->t('activity.error.invalid_date')];

            return;
        }

        $folder = $this->formFolderId === '' ? null : $this->resolveAvailableFolder($this->formFolderId);
        if ($this->formWithSubmissions && $folder === null) {
            $this->errors = ['folder' => $this->t('activity.error.folder_required')];

            return;
        }
        if ($this->formAutoComplete && $folder === null) {
            $this->errors = ['autoComplete' => $this->t('activity.error.auto_complete_requires_folder')];

            return;
        }
        // Naming the submissions after the leaf alone: two leaves with the same name would be the same submission.
        if ($listItem !== null && $folder !== null && !$this->formNameFullPath) {
            $duplicates = $this->submissionSlots->duplicateLeafNames($listItem);
            if ($duplicates !== []) {
                $this->errors = ['nameFullPath' => str_replace('%names%', implode('», «', array_slice($duplicates, 0, 5)), $this->t('activity.error.name_leaf_duplicated'))];

                return;
            }
        }

        // Restriction only applies to manual activities — a folder-backed one's ownership already
        // comes from the folder's own upload profiles, so $folder !== null always means general.
        $restricted = $folder === null && !$this->formGeneral;
        if ($restricted && $this->formProfileKeys === []) {
            $this->errors = ['profiles' => $this->t('activity.error.profiles_required')];

            return;
        }

        // «Datos de otras entregas»: each table of deliveries of the model reads an activity of the centre filled with the model it asks
        // for (an id from elsewhere never matches), and never one that, directly or through others, reads this one.
        $formModel       = $folder !== null && $this->formModelId !== '' ? $this->formModels->findByIdAndCentre($this->formModelId, $this->centre) : null;
        $deliverySources = [];
        foreach ($this->deliverySources->tablesOf($formModel) as $table) {
            $chosenId = $this->formDeliverySources[$table['key']] ?? '';
            $chosen   = null;
            foreach ($this->deliverySources->choicesFor($this->centre, $table['sourceModel'], $this->formActivityId) as $candidate) {
                $chosen = $candidate->getId()->toRfc4122() === $chosenId ? $candidate : $chosen;
            }
            if ($chosen === null) {
                $this->errors = ['delivery_' . $table['key'] => str_replace('%label%', $table['label'], $this->t('activity.error.delivery_source_required'))];

                return;
            }
            if ($this->formActivityId !== '' && $this->deliverySources->readsFrom($chosen, $this->formActivityId)) {
                $this->errors = ['delivery_' . $table['key'] => str_replace('%title%', $chosen->getTitle(), $this->t('activity.error.delivery_source_cycle'))];

                return;
            }
            $deliverySources[$table['key']] = $chosen;
        }

        $scope = ActivitySubmissionScope::from($this->formScope === 'individual' ? 'individual' : 'by_profile');

        $activity = null;
        if ($this->formActivityId !== '') {
            // $formActivityId is client-writable: an id from another centre must never be
            // edited (nor silently turned into a brand new activity here instead).
            $activity = $this->findActivity($this->formActivityId) ?? throw $this->createNotFoundException();
        }

        // «Una entrega por»: only with submissions; at most ActivityDimension::MAX lists, none the activity's own list (nor above or
        // under it); and, once something is delivered or drafted this year, how the submissions are split can no longer change.
        $dimensions = $folder === null ? [] : $this->getFormDimensionLists();
        $splitBy    = $listItem === null || $this->formSplitByElement;
        if (\count($dimensions) > \App\Entity\ActivityDimension::MAX) {
            $this->errors = ['dimensions' => $this->t('activity.error.dimensions_too_many')];

            return;
        }
        foreach ($dimensions as $dimension) {
            if ($listItem !== null && ($dimension->isUnder($listItem) || $listItem->isUnder($dimension))) {
                $this->errors = ['dimensions' => str_replace('%list%', $this->getListItemLabel($dimension), $this->t('activity.error.dimension_is_the_list'))];

                return;
            }
        }
        $dated      = $this->getFormDatedDimension();
        $datedIndex = null;
        foreach ($dimensions as $index => $dimension) {
            $datedIndex = $dated !== null && $dimension === $dated ? $index : $datedIndex;
        }
        if ($activity !== null) {
            $was     = array_map(static fn (\App\Entity\ActivityDimension $d): string => $d->getList()->getId()->toRfc4122(), $activity->getDimensions());
            $now     = array_map(static fn (ListItem $l): string => $l->getId()->toRfc4122(), $dimensions);
            // Only with dimensions before or after: without them nothing changes from what there always was.
            $changes = $was !== $now || $activity->isSplitByElement() !== $splitBy || $activity->getListItem() !== $listItem;
            if ($changes && ($was !== [] || $now !== [] || !$splitBy) && $this->isFormSplitLocked()) {
                $this->errors = ['dimensions' => $this->t('activity.error.dimensions_locked')];

                return;
            }
        }
        // Taken before anything is touched: the history records what this save changes.
        $before = $activity === null ? null : $this->changeRecorder->snapshot($activity);
        // What the delivered documents are named after, to rename them if only the naming changes (not the element).
        $wasFullPath   = $activity?->isSubmissionNameFullPath() ?? true;
        $wasListItemId = $activity?->getListItem()?->getId()->toRfc4122();
        if ($activity === null) {
            $activity = (new Activity())
                ->setCategory($category)
                ->setPosition($this->activities->nextPosition($category));
            $this->em->persist($activity);
        }

        $activity->setTitle($title);
        $activity->setDescription($this->formDescription !== '' ? $this->formDescription : null);
        $submissionPrefix = trim($this->formSubmissionPrefix);
        $activity->setSubmissionPrefix($submissionPrefix !== '' ? $submissionPrefix : null);
        $activity->setStart($startDay, $startMonth);
        $activity->setEnd($endDay, $endMonth);
        $activity->setListItem($listItem);
        $activity->setDimensions($dimensions, $datedIndex);
        $activity->setSplitByElement($splitBy);
        $activity->clearListItemDeadlines();
        foreach ($overridesByLeafId as $leafId => $range) {
            $leaf = $this->listItems->findByIdAndCentre($leafId, $this->centre);
            if ($leaf !== null) {
                $activity->setDeadlineOverride($leaf, $range['startDay'], $range['startMonth'], $range['endDay'], $range['endMonth']);
            }
        }
        $activity->setRequired($this->formRequired);
        $activity->setSubmissionScope($scope);
        $activity->setHidden($this->formHidden);
        $activity->setStartDateEnforced($this->formStartDateEnforced);
        $activity->setEndDateEnforced($this->formEndDateEnforced);
        // setEndDateGraceDays() zeroes itself when the end date isn't enforced.
        $activity->setEndDateGraceDays(max(0, (int) $this->formEndDateGraceDays));
        $activity->setFolder($folder);
        $activity->setSubmissionNameFullPath($listItem === null || $this->formNameFullPath);
        // The teachers of the elements only make sense for an activity of a catalogue element delivered as a shared one, not individually.
        $activity->setTeachersDeliver($listItem !== null && $folder !== null && $scope !== ActivitySubmissionScope::Individual && $this->formTeachersDeliver);
        // Same element, other naming: what is already delivered takes the new names, so its rows keep finding it.
        if ($wasListItemId !== null && $wasListItemId === $listItem?->getId()->toRfc4122()) {
            $this->submissionSlots->renameDocumentsForNameMode($activity, $wasFullPath);
        }
        // A model only makes sense where there is a folder to keep what is delivered; an id from another centre never matches.
        $activity->setFormModel($folder !== null && $this->formModelId !== '' ? $this->formModels->findByIdAndCentre($this->formModelId, $this->centre) : null);
        $activity->setFormDraftsForAll($activity->isForm() && $this->formDraftsForAll);
        $source = $activity->isForm() && $this->formPrefillSourceId !== '' ? $this->activities->findById($this->formPrefillSourceId) : null;
        $activity->setFormPrefillSource($source !== null && $source->getCategory()->getEducationalCentre()->getId()->equals($this->centre->getId()) ? $source : null);
        // The tables the model has now, with what was chosen; the choices of tables it no longer has go.
        foreach (array_keys($activity->getDeliverySources()) as $blockKey) {
            if (!isset($deliverySources[$blockKey])) {
                $activity->setDeliverySource($blockKey, null);
            }
        }
        foreach ($deliverySources as $blockKey => $deliveries) {
            $activity->setDeliverySource($blockKey, $deliveries);
        }
        // Safe unconditionally: the guard above already ensures $folder isn't null whenever
        // $this->formAutoComplete is true, and setAutoComplete(false) never throws either way.
        $activity->setAutoComplete($this->formAutoComplete);

        $rowsByKey = [];
        foreach ($this->getAvailableProfileRows() as $row) {
            $rowsByKey[$row->key()] = $row;
        }

        $activity->setGeneral(!$restricted);
        $activity->clearProfileRestrictions();
        if ($restricted) {
            foreach ($this->formProfileKeys as $key) {
                $row = $rowsByKey[$key] ?? null;
                if ($row !== null) {
                    $activity->addProfileRestriction($row->profile, $row->listItem);
                }
            }
        }

        // Responsible profiles are meaningful only for a manual activity — a folder-backed one's
        // management already comes from the folder's own FolderResponsibleProfile rows.
        $activity->clearResponsibleProfiles();
        if ($folder === null) {
            foreach ($this->formResponsibleProfileKeys as $key) {
                $row = $rowsByKey[$key] ?? null;
                if ($row !== null) {
                    $activity->addResponsibleProfile($row->profile, $row->listItem);
                }
            }
        }

        foreach (iterator_to_array($activity->getRelatedDocuments()) as $document) {
            if (!in_array($document->getId()->toRfc4122(), $this->formRelatedDocumentIds, true)) {
                $activity->removeRelatedDocument($document);
            }
        }
        foreach ($this->formRelatedDocumentIds as $documentId) {
            $document = $this->findViewableDocumentById($documentId);
            if ($document !== null) {
                $activity->addRelatedDocument($document);
            }
        }

        // The folder's own profile lists, as edited in the form (rows not on offer are ignored).
        if ($folder !== null) {
            $this->folderProfiles->syncAll(
                $folder,
                $this->formFolderResponsibleKeys,
                $this->formFolderUploadKeys,
                $this->formFolderVisibilityKeys,
                $this->formFolderReviewKeys,
                $rowsByKey,
            );
        }

        if ($before === null) {
            $this->changeRecorder->recordCreated($activity, $this->teacher());
        } else {
            $this->changeRecorder->recordUpdated($activity, $before, $this->teacher());
        }
        $this->em->flush();

        // Documents already sitting in a newly linked folder become this occurrence's
        // submissions (the ones uploaded through the activity get stamped as they're created).
        if ($folder !== null) {
            $this->documents->assignActivityCycleYearWhereMissing($folder, $this->deadline->currentCycleKey($activity));
        }

        $this->activityFormOpen = false;
        $this->errors           = [];
        $this->flashSuccess($this->t('activity.flash.saved'));
    }

    /**
     * Reads the formOverrideStartDay/StartMonth/EndDay/EndMonth props for $leaves into one
     * validated {leafId: {startDay, startMonth, endDay, endMonth}} map — a leaf with all 4 fields blank is
     * simply left out (meaning "use the activity's own dates"); one with any field filled must have
     * all 4 valid, or the whole form is rejected (returns false) exactly like the activity's own
     * date fields.
     *
     * @param  ListItem[] $leaves
     * @return array<string, array{startDay: int, startMonth: int, endDay: int, endMonth: int}>|false
     */
    private function readDeadlineOverrides(array $leaves): array|false
    {
        $overrides = [];
        foreach ($leaves as $leaf) {
            $leafId = $leaf->getId()->toRfc4122();
            $raw    = [
                $this->formOverrideStartDay[$leafId] ?? '',
                $this->formOverrideStartMonth[$leafId] ?? '',
                $this->formOverrideEndDay[$leafId] ?? '',
                $this->formOverrideEndMonth[$leafId] ?? '',
            ];
            if ($raw === ['', '', '', '']) {
                continue;
            }
            if (in_array('', $raw, true)) {
                return false;
            }

            [$startDay, $startMonth, $endDay, $endMonth] = array_map(static fn (string $v): int => (int) $v, $raw);
            if ($startDay < 1 || $startDay > 31 || $startMonth < 1 || $startMonth > 12 || $endDay < 1 || $endDay > 31 || $endMonth < 1 || $endMonth > 12) {
                return false;
            }

            $overrides[$leafId] = ['startDay' => $startDay, 'startMonth' => $startMonth, 'endDay' => $endDay, 'endMonth' => $endMonth];
        }

        return $overrides;
    }

    private function resolveAvailableFolder(string $id): ?Folder
    {
        foreach ($this->getAvailableFolders() as $folder) {
            if ($folder->getId()->toRfc4122() === $id) {
                return $folder;
            }
        }

        return null;
    }

    /** A related document must belong to this centre and stay within what the editing teacher can actually see — re-checked here since $formRelatedDocumentIds is client-controlled state. */
    private function findViewableDocumentById(string $id): ?Document
    {
        $document = $this->findDocument($id);
        if ($document === null) {
            return null;
        }

        return $this->access->canViewDocument($this->teacher(), $document) ? $document : null;
    }

    #[LiveAction]
    public function askDeleteActivity(#[LiveArg] string $id): void
    {
        $this->requireEditPermission();
        $this->confirmingDeleteActivityId = $id;
    }

    #[LiveAction]
    public function cancelDeleteActivity(): void
    {
        $this->confirmingDeleteActivityId = '';
    }

    #[LiveAction]
    public function deleteActivity(#[LiveArg] string $id): void
    {
        $this->requireEditPermission();
        $activity = $this->findActivity($id);
        if ($activity === null) {
            $this->confirmingDeleteActivityId = '';

            return;
        }

        $this->trash->trashActivity($activity, $this->teacher());

        $this->confirmingDeleteActivityId = '';
        $this->flashSuccess($this->t('activity.flash.deleted'));
    }

    private function requireEditPermission(): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
    }
}
