<?php

declare(strict_types=1);

namespace Neos\Neos\Security\Authorization;

use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTags;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Authorization\PrivilegeManagerInterface;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Flow\Security\Policy\Role;
use Neos\Neos\Domain\Model\NodePermissions;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Model\WorkspacePermissions;
use Neos\Neos\Domain\Model\WorkspaceRole;
use Neos\Neos\Domain\Model\WorkspaceRoleSubject;
use Neos\Neos\Domain\Model\WorkspaceRoleSubjects;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\Security\Authorization\Privilege\EditNodePrivilege;
use Neos\Neos\Security\Authorization\Privilege\ReadNodePrivilege;
use Neos\Neos\Security\Authorization\Privilege\SubtreeTagPrivilegeSubject;
use Neos\Party\Domain\Service\PartyService;

/**
 * Central point which does ContentRepository authorization decisions within Neos.
 *
 * @api
 */
#[Flow\Scope('singleton')]
final readonly class ContentRepositoryAuthorizationService
{
    private const FLOW_ROLE_EVERYBODY = 'Neos.Flow:Everybody';
    private const FLOW_ROLE_ANONYMOUS = 'Neos.Flow:Anonymous';
    private const FLOW_ROLE_AUTHENTICATED_USER = 'Neos.Flow:AuthenticatedUser';
    private const FLOW_ROLE_NEOS_ADMINISTRATOR = 'Neos.Neos:Administrator';

    public function __construct(
        private PartyService $partyService,
        private WorkspaceService $workspaceService,
        private ContentRepositoryRegistry $contentRepositoryRegistry,
        private PolicyService $policyService,
        private PrivilegeManagerInterface $privilegeManager,
    ) {
    }

    /**
     * Determines the {@see WorkspacePermissions} an anonymous user has for the specified workspace (aka "public access")
     */
    public function getWorkspacePermissionsForAnonymousUser(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName): WorkspacePermissions
    {
        $subjects = [WorkspaceRoleSubject::createForGroup(self::FLOW_ROLE_EVERYBODY), WorkspaceRoleSubject::createForGroup(self::FLOW_ROLE_ANONYMOUS)];
        $userWorkspaceRole = $this->workspaceService->getMostPrivilegedWorkspaceRoleForSubjects($contentRepositoryId, $workspaceName, WorkspaceRoleSubjects::fromArray($subjects));
        if ($userWorkspaceRole === null) {
            return WorkspacePermissions::none(sprintf('Anonymous user has no explicit role for workspace "%s"', $workspaceName->value));
        }
        return WorkspacePermissions::create(
            read: $userWorkspaceRole->isAtLeast(WorkspaceRole::VIEWER),
            write: $userWorkspaceRole->isAtLeast(WorkspaceRole::COLLABORATOR),
            manage: $userWorkspaceRole->isAtLeast(WorkspaceRole::MANAGER),
            reason: sprintf('Anonymous user has role "%s" for workspace "%s"', $userWorkspaceRole->value, $workspaceName->value),
        );
    }

    /**
     * Determines the {@see WorkspacePermissions} the given user has for the specified workspace
     */
    public function getWorkspacePermissionsForAccount(ContentRepositoryId $contentRepositoryId, WorkspaceName $workspaceName, Account $account): WorkspacePermissions
    {
        $workspaceMetadata = $this->workspaceService->getWorkspaceMetadata($contentRepositoryId, $workspaceName);
        $neosUser = $this->neosUserFromAccount($account);
        if ($workspaceMetadata->ownerUserId !== null && $neosUser !== null && $neosUser->getId()->equals($workspaceMetadata->ownerUserId)) {
            return WorkspacePermissions::all(sprintf('User "%s" (id: %s is the owner of workspace "%s"', $neosUser->getLabel(), $neosUser->getId()->value, $workspaceName->value));
        }
        $userRoles = $this->expandAccountRoles($account);
        $userIsAdministrator = array_key_exists(self::FLOW_ROLE_NEOS_ADMINISTRATOR, $userRoles);
        $subjects = array_map(WorkspaceRoleSubject::createForGroup(...), array_keys($userRoles));

        if ($neosUser !== null) {
            $subjects[] = WorkspaceRoleSubject::createForUser($neosUser->getId());
        }
        $userWorkspaceRole = $this->workspaceService->getMostPrivilegedWorkspaceRoleForSubjects($contentRepositoryId, $workspaceName, WorkspaceRoleSubjects::fromArray($subjects));
        if ($userWorkspaceRole === null) {
            if ($userIsAdministrator) {
                return WorkspacePermissions::manage(sprintf('Account "%s" is a Neos Administrator without explicit role for workspace "%s"', $account->getAccountIdentifier(), $workspaceName->value));
            }
            return WorkspacePermissions::none(sprintf('Account "%s" is no Neos Administrator and has no explicit role for workspace "%s"', $account->getAccountIdentifier(), $workspaceName->value));
        }
        return WorkspacePermissions::create(
            read: $userWorkspaceRole->isAtLeast(WorkspaceRole::VIEWER),
            write: $userWorkspaceRole->isAtLeast(WorkspaceRole::COLLABORATOR),
            manage: $userIsAdministrator || $userWorkspaceRole->isAtLeast(WorkspaceRole::MANAGER),
            reason: sprintf('Account "%s" is %s Neos Administrator and has role "%s" for workspace "%s"', $account->getAccountIdentifier(), $userIsAdministrator ? 'a' : 'no', $userWorkspaceRole->value, $workspaceName->value),
        );
    }

    public function getNodePermissionsForAnonymousUser(Node|NodeAddress $node): NodePermissions
    {
        $roles = $this->rolesOfAnonymousUser();
        return $this->nodePermissionsForRoles($node, $roles);
    }

    public function getNodePermissionsForAccount(Node|NodeAddress $node, Account $account): NodePermissions
    {
        $roles = $this->expandAccountRoles($account);
        return $this->nodePermissionsForRoles($node, $roles);
    }

    /**
     * Determines the default {@see VisibilityConstraints} for an anonymous user (aka "public access")
     */
    public function getVisibilityConstraintsForAnonymousUser(ContentRepositoryId $contentRepositoryId): VisibilityConstraints
    {
        $roles = $this->rolesOfAnonymousUser();
        return VisibilityConstraints::fromTagConstraints($this->tagConstraintsForRoles($contentRepositoryId, $roles));
    }

    /**
     * Determines the default {@see VisibilityConstraints} for the specified account
     */
    public function getVisibilityConstraintsForAccount(ContentRepositoryId $contentRepositoryId, Account $account): VisibilityConstraints
    {
        $roles = $this->expandAccountRoles($account);
        return VisibilityConstraints::fromTagConstraints($this->tagConstraintsForRoles($contentRepositoryId, $roles));
    }

    // ------------------------------

    /**
     * @return array<Role>
     */
    private function rolesOfAnonymousUser(): array
    {
        return [
            self::FLOW_ROLE_EVERYBODY => $this->policyService->getRole(self::FLOW_ROLE_EVERYBODY),
            self::FLOW_ROLE_ANONYMOUS => $this->policyService->getRole(self::FLOW_ROLE_ANONYMOUS),
        ];
    }

    /**
     * @return array<Role>
     */
    private function expandAccountRoles(Account $account): array
    {
        $roles = [
            self::FLOW_ROLE_EVERYBODY => $this->policyService->getRole(self::FLOW_ROLE_EVERYBODY),
            self::FLOW_ROLE_AUTHENTICATED_USER => $this->policyService->getRole(self::FLOW_ROLE_AUTHENTICATED_USER),
        ];
        foreach ($account->getRoles() as $currentRole) {
            if (!array_key_exists($currentRole->getIdentifier(), $roles)) {
                $roles[$currentRole->getIdentifier()] = $currentRole;
            }
            foreach ($currentRole->getAllParentRoles() as $currentParentRole) {
                if (!array_key_exists($currentParentRole->getIdentifier(), $roles)) {
                    $roles[$currentParentRole->getIdentifier()] = $currentParentRole;
                }
            }
        }
        return $roles;
    }

    /**
     * @param array<Role> $roles
     */
    private function tagConstraintsForRoles(ContentRepositoryId $contentRepositoryId, array $roles): SubtreeTags
    {
        $restrictedSubtreeTags = SubtreeTags::createEmpty();
        /** @var ReadNodePrivilege $privilege */
        foreach ($this->policyService->getAllPrivilegesByType(ReadNodePrivilege::class) as $privilege) {
            if (!$this->privilegeManager->isGrantedForRoles($roles, ReadNodePrivilege::class, new SubtreeTagPrivilegeSubject($privilege->getSubtreeTags(), $contentRepositoryId))) {
                $restrictedSubtreeTags = $restrictedSubtreeTags->merge($privilege->getSubtreeTags());
            }
        }
        return $restrictedSubtreeTags;
    }

    private function neosUserFromAccount(Account $account): ?User
    {
        $user = $this->partyService->getAssignedPartyOfAccount($account);
        return $user instanceof User ? $user : null;
    }

    /**
     * @param array<Role> $roles
     */
    private function nodePermissionsForRoles(Node|NodeAddress $node, array $roles): NodePermissions
    {
        if ($node instanceof NodeAddress) {
            $converted = $this->nodeForNodeAddress($node);
            if ($converted === null) {
                return NodePermissions::none(sprintf('Node "%s" not found in Content Repository "%s"', $node->aggregateId->value, $node->contentRepositoryId->value));
            }
            $node = $converted;
        }
        $subtreeTagPrivilegeSubject = new SubtreeTagPrivilegeSubject($node->tags->all(), $node->contentRepositoryId);
        $readGranted = $this->privilegeManager->isGrantedForRoles($roles, ReadNodePrivilege::class, $subtreeTagPrivilegeSubject, $readReason);
        $writeGranted = $this->privilegeManager->isGrantedForRoles($roles, EditNodePrivilege::class, $subtreeTagPrivilegeSubject, $writeReason);
        return NodePermissions::create(
            read: $readGranted,
            edit: $writeGranted,
            reason: $readReason . "\n" . $writeReason,
        );
    }

    private function nodeForNodeAddress(NodeAddress $nodeAddress): ?Node
    {
        return $this->contentRepositoryRegistry->get($nodeAddress->contentRepositoryId)
            ->getContentGraph($nodeAddress->workspaceName)
            ->getSubgraph($nodeAddress->dimensionSpacePoint, VisibilityConstraints::withoutRestrictions())
            ->findNodeById($nodeAddress->aggregateId);
    }
}