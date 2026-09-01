<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security\Voter;

use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Security\Voter\FolderVoter;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class FolderVoterTest extends RepositoryTestCase
{
    private FolderVoter $voter;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var FolderVoter $voter */
        $voter       = self::getContainer()->get(FolderVoter::class);
        $this->voter = $voter;
    }

    private function tokenFor(?Teacher $teacher): TokenInterface
    {
        $token = self::createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($teacher);

        return $token;
    }

    private function centre(string $code = '12345678'): EducationalCentre
    {
        return (new EducationalCentre())->setCode($code)->setName('Centro')->setCity('Ciudad');
    }

    private function folder(EducationalCentre $centre): Folder
    {
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');

        return (new Folder())->setDocumentSection($section)->setName('Carpeta');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    private function vote(?Teacher $teacher, string $attribute, Folder $folder): int
    {
        return $this->voter->vote($this->tokenFor($teacher), $folder, [$attribute]);
    }

    public function testSupportsOnlyItsOwnAttributesAndFolderSubjects(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $folder->getDocumentSection(), $folder, $teacher);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($this->tokenFor($teacher), $folder, ['unknown.attribute']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($this->tokenFor($teacher), new \stdClass(), [FolderVoter::VIEW]));
    }

    public function testDeniesAnonymousUser(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($centre);
        $this->persist($centre, $folder->getDocumentSection(), $folder);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(null, FolderVoter::VIEW, $folder));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(null, FolderVoter::MANAGE, $folder));
    }

    public function testAdminBypassesEveryAttribute(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($centre);
        $admin  = $this->teacher('admin');
        $admin->setAdmin(true);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $admin);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($admin, FolderVoter::VIEW, $folder));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($admin, FolderVoter::MANAGE, $folder));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($admin, FolderVoter::UPLOAD, $folder));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($admin, FolderVoter::REVIEW, $folder));
    }

    public function testCentreQualityManagerBypassesEveryAttribute(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($centre);
        $qm     = $this->teacher('calidad');
        $centre->getQualityManagers()->add($qm);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $qm);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($qm, FolderVoter::MANAGE, $folder));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($qm, FolderVoter::UPLOAD, $folder));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($qm, FolderVoter::REVIEW, $folder));
    }

    public function testViewIsOpenByDefault(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $folder->getDocumentSection(), $folder, $teacher);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($teacher, FolderVoter::VIEW, $folder));
    }

    public function testViewDeniedWhenRestrictedAndTeacherLacksProfile(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil visible');
        $folder->addVisibilityProfile($profile);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $teacher);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($teacher, FolderVoter::VIEW, $folder));
    }

    public function testViewGrantedWhenRestrictedAndTeacherHoldsProfileDirectly(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil visible');
        $folder->addVisibilityProfile($profile);
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $teacher, $assignment);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($teacher, FolderVoter::VIEW, $folder));
    }

    public function testViewGrantedByWildcardVisibilityProfile(): void
    {
        $centre     = $this->centre();
        $folder     = $this->folder($centre);
        $subprofile = (new ListItem())->setEducationalCentre($centre)->setName('Subprofile');
        $profile    = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($subprofile);
        $folder->addVisibilityProfile($profile, null); // "(todos)" wildcard, matches any subprofile holder
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, $subprofile, $teacher);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $subprofile, $profile, $teacher, $assignment);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($teacher, FolderVoter::VIEW, $folder));
    }

    public function testAdminSeesRestrictedFolderEvenWithoutProfile(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil visible');
        $folder->addVisibilityProfile($profile);
        $admin = $this->teacher('admin');
        $admin->setAdmin(true);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $admin);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($admin, FolderVoter::VIEW, $folder));
    }

    public function testManageGrantedByDirectResponsibleProfile(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable');
        $folder->addResponsibleProfile($profile);
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $teacher, $assignment);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($teacher, FolderVoter::MANAGE, $folder));
        // A manager can also upload and review, even without their own upload/review row.
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($teacher, FolderVoter::UPLOAD, $folder));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($teacher, FolderVoter::REVIEW, $folder));
    }

    public function testManageGrantedByWildcardResponsibleProfile(): void
    {
        $centre     = $this->centre();
        $folder     = $this->folder($centre);
        $subprofile = (new ListItem())->setEducationalCentre($centre)->setName('Subprofile');
        $profile    = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($subprofile);
        $folder->addResponsibleProfile($profile, null);
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, $subprofile, $teacher);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $subprofile, $profile, $teacher, $assignment);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($teacher, FolderVoter::MANAGE, $folder));
    }

    public function testManageDeniedForUnrelatedTeacher(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable');
        $folder->addResponsibleProfile($profile);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $teacher);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($teacher, FolderVoter::MANAGE, $folder));
    }

    public function testUploadGrantedByDirectUploadProfileWithoutBeingManager(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Subidor');
        $folder->addUploadProfile($profile);
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $teacher, $assignment);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($teacher, FolderVoter::UPLOAD, $folder));
        // Uploading is not managing: no rights over other people's revisions.
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($teacher, FolderVoter::MANAGE, $folder));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($teacher, FolderVoter::REVIEW, $folder));
    }

    public function testUploadGrantedByWildcardUploadProfile(): void
    {
        $centre     = $this->centre();
        $folder     = $this->folder($centre);
        $subprofile = (new ListItem())->setEducationalCentre($centre)->setName('Subprofile');
        $profile    = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($subprofile);
        $folder->addUploadProfile($profile, null);
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, $subprofile, $teacher);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $subprofile, $profile, $teacher, $assignment);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($teacher, FolderVoter::UPLOAD, $folder));
    }

    public function testUploadDeniedWithoutMatchingProfile(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Subidor');
        $folder->addUploadProfile($profile);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $teacher);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($teacher, FolderVoter::UPLOAD, $folder));
    }

    public function testReviewGrantedByDirectReviewProfileWithoutBeingManager(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor');
        $folder->addReviewProfile($profile);
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $teacher, $assignment);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($teacher, FolderVoter::REVIEW, $folder));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($teacher, FolderVoter::MANAGE, $folder));
    }

    public function testReviewGrantedByWildcardReviewProfile(): void
    {
        $centre     = $this->centre();
        $folder     = $this->folder($centre);
        $subprofile = (new ListItem())->setEducationalCentre($centre)->setName('Subprofile');
        $profile    = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($subprofile);
        $folder->addReviewProfile($profile, null);
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, $subprofile, $teacher);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $subprofile, $profile, $teacher, $assignment);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($teacher, FolderVoter::REVIEW, $folder));
    }

    public function testReviewDeniedWithoutMatchingProfile(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor');
        $folder->addReviewProfile($profile);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $teacher);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($teacher, FolderVoter::REVIEW, $folder));
    }

    /** A teacher's assignment in one centre must never grant access to a same-named-profile folder in another centre. */
    public function testCrossCentreAssignmentNeverGrantsAccess(): void
    {
        $centreA  = $this->centre('11111111');
        $centreB  = $this->centre('22222222');
        $folderB  = $this->folder($centreB);
        $profileA = (new SpecificProfile())->setEducationalCentre($centreA)->setName('Responsable');
        $profileB = (new SpecificProfile())->setEducationalCentre($centreB)->setName('Responsable');
        $folderB->addResponsibleProfile($profileB);

        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profileA, null, $teacher);
        $this->persist($centreA, $centreB, $folderB->getDocumentSection(), $folderB, $profileA, $profileB, $teacher, $assignment);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($teacher, FolderVoter::MANAGE, $folderB));
    }
}
