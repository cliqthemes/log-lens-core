<?php
declare(strict_types=1);

namespace LogLens\Tests\Unit;

use LogLens\Authorization\RoleAuthorizer;
use LogLens\Http\LogLensRequest;
use LogLens\Identity\Actor;
use LogLens\Identity\SystemAssignableUsersResolver;
use LogLens\Identity\SystemIdentityResolver;
use LogLens\Tests\TestCase;

/**
 * C-2: identity + authorization abstraction.
 */
final class AuthorizationTest extends TestCase
{
    public function testActorRoleHelpers(): void
    {
        $actor = new Actor('u1', 'Alice', [Actor::ROLE_EDITOR]);
        self::assertTrue($actor->hasRole(Actor::ROLE_EDITOR));
        self::assertFalse($actor->isOwner());
        self::assertTrue(Actor::localOwner()->isOwner());
    }

    public function testOwnerCanDoEverything(): void
    {
        $authorizer = new RoleAuthorizer();
        $owner = Actor::localOwner();
        self::assertTrue($authorizer->can($owner, 'issues.write'));
        self::assertTrue($authorizer->can($owner, 'plugins.manage'));
        self::assertTrue($authorizer->can($owner, 'settings.write'));
    }

    public function testEditorCanWriteIssuesButNotAdminister(): void
    {
        $authorizer = new RoleAuthorizer();
        $editor = new Actor('u2', 'Ed', [Actor::ROLE_EDITOR]);
        self::assertTrue($authorizer->can($editor, 'issues.write'));
        self::assertFalse($authorizer->can($editor, 'plugins.manage'));
        self::assertFalse($authorizer->can($editor, 'settings.write'));
    }

    public function testViewerIsReadOnly(): void
    {
        $authorizer = new RoleAuthorizer();
        $viewer = new Actor('u3', 'Val', [Actor::ROLE_VIEWER]);
        self::assertTrue($authorizer->can($viewer, 'issues.read'));
        self::assertFalse($authorizer->can($viewer, 'issues.write'));
    }

    public function testProvisioningAnApplicationIsOwnerOnlyButListingIsNot(): void
    {
        $authorizer = new RoleAuthorizer();
        $editor = new Actor('u2', 'Ed', [Actor::ROLE_EDITOR]);
        $viewer = new Actor('u3', 'Val', [Actor::ROLE_VIEWER]);

        // Creating an application provisions a new tenant database + directories.
        self::assertTrue($authorizer->can(Actor::localOwner(), 'applications.write'));
        self::assertFalse($authorizer->can($editor, 'applications.write'));
        self::assertFalse($authorizer->can($viewer, 'applications.write'));

        // Everyone who can use the dashboard can see which applications exist —
        // the app switcher is unusable otherwise.
        self::assertTrue($authorizer->can($editor, 'applications.read'));
        self::assertTrue($authorizer->can($viewer, 'applications.read'));
    }

    /**
     * The editor rule reads "everything except administration" — reads of
     * administrative resources still have to work, or an editor cannot see the
     * settings screen they are allowed to look at.
     */
    public function testEditorCanReadAdministrativeResourcesWithoutWritingThem(): void
    {
        $authorizer = new RoleAuthorizer();
        $editor = new Actor('u2', 'Ed', [Actor::ROLE_EDITOR]);
        self::assertTrue($authorizer->can($editor, 'settings.read'));
        self::assertTrue($authorizer->can($editor, 'plugins.read'));
        self::assertFalse($authorizer->can($editor, 'settings.write'));
    }

    public function testResolverUsesTransportActorThenFallsBackToLocalOwner(): void
    {
        $resolver = new SystemIdentityResolver();

        $anonymous = new LogLensRequest('GET', [], []);
        self::assertTrue($resolver->resolve($anonymous)->isOwner(), 'No actor ⇒ local owner.');

        $supplied = new Actor('u9', 'Nine', [Actor::ROLE_VIEWER]);
        $withActor = new LogLensRequest('GET', [], [], [], null, null, null, $supplied);
        self::assertSame('u9', $resolver->resolve($withActor)->id);
    }

    public function testAssignableUsersResolverDefaultsToEmptyAndReflectsTheTransportList(): void
    {
        $resolver = new SystemAssignableUsersResolver();

        $bare = new LogLensRequest('GET', [], []);
        self::assertSame([], $resolver->resolve($bare), 'Standalone (no transport-supplied list) has nobody assignable.');

        $withList = new LogLensRequest('GET', [], [], [], null, null, null, null, [['id' => 'u1', 'label' => 'Alice']]);
        self::assertSame([['id' => 'u1', 'label' => 'Alice']], $resolver->resolve($withList));
    }
}
