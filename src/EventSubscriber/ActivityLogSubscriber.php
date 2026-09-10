<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\ActivityLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;
use Symfony\Component\Security\Http\Firewall\SwitchUserListener;

/**
 * Feeds the activity log on kernel.terminate (after the response is sent):
 *  - every write request (POST/PUT/PATCH/DELETE) and every sensitive read (download/export/report)
 *    gets one generic `http.request` entry, unless a controller already recorded an explicit one;
 *  - Live Component actions listed in COMPONENT_ACTIONS are recorded with a semantic action type
 *    (folder/list/section CRUD, folder navigation, document opening…); everything else a Live
 *    Component does — opening a confirm box, paging, restoring state from the URL, toggling a
 *    filter, selecting a row — is not audit-worthy and is skipped;
 *  - login, logout and impersonation are recorded from the security events.
 *
 * Every action type this class or a controller emits has a matching `activity_log.action.<type>`
 * label in translations/admin.es.yaml.
 *
 * All entries are buffered by ActivityLogger and flushed here.
 */
final class ActivityLogSubscriber
{
    /** Route-name prefixes that never produce an entry. */
    private const EXCLUDED_ROUTE_PREFIXES = ['_profiler', '_wdt', '_error', 'app_login', 'app_logout'];

    /** Substrings that mark a GET route as a sensitive read worth recording. */
    private const SENSITIVE_ROUTE_PATTERNS = ['descargar', 'download', 'export', 'informe', 'report', 'zip'];

    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const LIVE_COMPONENT_ROUTE = 'ux_live_component';

    /**
     * `<component name> => <LiveAction method> => <semantic action type>`. Only the actions listed
     * here are recorded; the many that aren't (selection, confirm dialogs, panel toggles, list
     * paging/filtering…) carry no audit value. Several actions deliberately share one type —
     * every "move up / move down / sort" is a `*.reorder`.
     *
     * @var array<string, array<string, string>>
     */
    private const COMPONENT_ACTIONS = [
        'SectionBrowserComponent' => [
            'openLevel'                   => 'document_tree.section_open',
            'toggleFolder'                => 'folder.open',
            'toggleRevisionPanel'         => 'document.open',
            'addFolder'                   => 'folder.create',
            'saveRenameFolder'            => 'folder.rename',
            'deleteFolder'                => 'folder.delete',
            'moveFolderUp'                => 'folder.reorder',
            'moveFolderDown'              => 'folder.reorder',
            'toggleGroupByProfile'        => 'folder.settings_update',
            'toggleAutoArchive'           => 'folder.settings_update',
            'toggleObsolete'              => 'folder.settings_update',
            'saveFolderProfiles'          => 'folder.settings_update',
            'saveRenameDocument'          => 'document.rename',
            'deleteDocument'              => 'document.delete',
            'moveDocument'                => 'document.move',
            'moveDocumentUp'              => 'document.reorder',
            'moveDocumentDown'            => 'document.reorder',
            'sortDocumentsAlphabetically' => 'document.reorder',
            'setActiveRevision'           => 'document.set_active_revision',
            'saveEditRevision'            => 'document.revision_edit',
            'deleteRevision'              => 'document.revision_delete',
        ],
        'ActivityBrowserComponent' => [
            'openLevel'             => 'activity.category_open',
            'toggleRevisionPanel'  => 'document.open',
            'saveActivity'         => 'activity.save',
            'deleteActivity'       => 'activity.delete',
            'markCompleted'        => 'activity.mark_complete',
            'unmarkCompleted'      => 'activity.unmark_complete',
            'addRelatedDocument'   => 'activity.related_documents_update',
            'removeRelatedDocument' => 'activity.related_documents_update',
            'deleteDocument'       => 'document.delete',
            'setActiveRevision'    => 'document.set_active_revision',
            'saveEditRevision'     => 'document.revision_edit',
            'deleteRevision'       => 'document.revision_delete',
        ],
        'SettingsComponent' => [
            'save'       => 'settings.update',
            'toggleLock' => 'settings.lock_toggle',
        ],
        'Admin:ListItemTreeComponent' => [
            'addItem'             => 'list_item.created',
            'saveDetail'          => 'list_item.updated',
            'deleteSelected'      => 'list_item.deleted',
            'moveUp'              => 'list_item.reorder',
            'moveDown'            => 'list_item.reorder',
            'moveListItem'        => 'list_item.reorder',
            'sortAlphabetically'  => 'list_item.reorder',
            'addTag'              => 'list_item.tags_update',
            'removeTag'           => 'list_item.tags_update',
            'saveAssociation'     => 'list_item.association_update',
            'bulkSaveAssociation' => 'list_item.association_update',
        ],
        'Admin:DocumentSectionTreeComponent' => [
            'addSection'               => 'document_section.created',
            'saveRename'               => 'document_section.updated',
            'deleteSection'            => 'document_section.deleted',
            'moveUp'                   => 'document_section.reorder',
            'moveDown'                 => 'document_section.reorder',
            'moveSection'              => 'document_section.reorder',
            'toggleProfileRestriction' => 'document_section.restrictions_update',
        ],
        'Admin:ActivityCategoryTreeComponent' => [
            'addCategory'    => 'activity_category.created',
            'saveRename'     => 'activity_category.updated',
            'deleteCategory' => 'activity_category.deleted',
            'moveUp'         => 'activity_category.reorder',
            'moveDown'       => 'activity_category.reorder',
        ],
        'Admin:SpecificProfileTreeComponent' => [
            'addProfile'           => 'specific_profile.created',
            'saveDetail'           => 'specific_profile.updated',
            'deleteSelected'       => 'specific_profile.deleted',
            'moveUp'               => 'specific_profile.reorder',
            'moveDown'             => 'specific_profile.reorder',
            'sortAlphabetically'   => 'specific_profile.reorder',
            'pickListItem'         => 'specific_profile.list_association_update',
            'clearListAssociation' => 'specific_profile.list_association_update',
            'assignTeacher'        => 'profile_assignment.updated',
            'removeTeacher'        => 'profile_assignment.updated',
        ],
        'Admin:SpecificProfileAssignmentsComponent' => [
            'assignTeacherToRow'   => 'profile_assignment.updated',
            'removeTeacherFromRow' => 'profile_assignment.updated',
            'assignRowToTeacher'   => 'profile_assignment.updated',
            'removeRowFromTeacher' => 'profile_assignment.updated',
            'bulkRemoveOffYear'    => 'profile_assignment.updated',
        ],
    ];

    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly TokenStorageInterface $tokenStorage,
    ) {}

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        try {
            $request = $event->getRequest();
            $route   = $request->attributes->getString('_route');
            $status  = $event->getResponse()->getStatusCode();

            $alreadyExplicit = $this->activityLogger->hasExplicitEntries()
                || $request->attributes->getBoolean('_activity_log_explicit');

            if ($alreadyExplicit || $route === '' || $this->isExcludedRoute($route)) {
                return;
            }

            if ($route === self::LIVE_COMPONENT_ROUTE) {
                $this->recordLiveComponentAction($request, $status);

                return;
            }

            $isWrite     = \in_array($request->getMethod(), self::WRITE_METHODS, true);
            $isSensitive = !$isWrite && $this->isSensitiveRoute($route);

            if ($isWrite || $isSensitive) {
                $this->activityLogger->recordFromRequest($request, 'http.request', [
                    'method' => $request->getMethod(),
                    'path'   => $request->getPathInfo(),
                    'status' => $status,
                ]);
            }
        } catch (\Throwable) {
            // Never let auditing break the request lifecycle.
        } finally {
            $this->activityLogger->flush();
        }
    }

    #[AsEventListener]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $this->activityLogger->recordForToken($event->getAuthenticatedToken(), 'session.login', $event->getRequest());
    }

    #[AsEventListener]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $username = $event->getPassport()?->getBadge(UserBadge::class)?->getUserIdentifier();

        $this->activityLogger->recordForToken(
            null,
            'session.login_failed',
            $event->getRequest(),
            $username !== null && $username !== '' ? ['username' => $username] : [],
        );
    }

    #[AsEventListener]
    public function onLogout(LogoutEvent $event): void
    {
        $this->activityLogger->recordForToken($event->getToken(), 'session.logout', $event->getRequest());
    }

    #[AsEventListener]
    public function onSwitchUser(SwitchUserEvent $event): void
    {
        $request = $event->getRequest();
        $exiting = $request->query->getString('_switch_user') === SwitchUserListener::EXIT_VALUE;

        // On start, storage already holds the new SwitchUserToken; on stop, it still holds the
        // impersonated user's SwitchUserToken. resolveUsers() derives active vs real from either.
        $this->activityLogger->recordForToken(
            $this->tokenStorage->getToken(),
            $exiting ? 'session.impersonate_stop' : 'session.impersonate_start',
            $request,
            ['target' => $event->getTargetUser()->getUserIdentifier()],
        );
    }

    private function recordLiveComponentAction(Request $request, int $status): void
    {
        $component  = $request->attributes->getString('_live_component');
        $action     = $request->attributes->getString('_live_action');
        $actionType = self::COMPONENT_ACTIONS[$component][$action] ?? null;

        if ($actionType === null) {
            return;
        }

        // The semantic action type already says what happened; the raw component/action names
        // would only add camelCase noise to the detail column.
        $this->activityLogger->recordFromRequest($request, $actionType, ['status' => $status]);
    }

    private function isExcludedRoute(string $route): bool
    {
        foreach (self::EXCLUDED_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isSensitiveRoute(string $route): bool
    {
        foreach (self::SENSITIVE_ROUTE_PATTERNS as $pattern) {
            if (str_contains($route, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
