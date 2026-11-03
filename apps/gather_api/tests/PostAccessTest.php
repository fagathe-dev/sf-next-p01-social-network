<?php

declare(strict_types=1);

namespace App\Tests;

use Fagathe\GatherCore\Entity\GroupMember;
use Fagathe\GatherCore\Enum\Auth\UserStatus;
use Fagathe\GatherCore\Enum\Social\ContentStatus;
use Fagathe\GatherCore\Enum\Social\GroupVisibility;
use Fagathe\GatherCore\Enum\Social\PostVisibility;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Règles de confidentialité et d'engagement de la section 2.6.
 */
final class PostAccessTest extends ApiTestCase
{
    public function testVisitorSeesPublicPostsOnly(): void
    {
        $author = $this->user('clara');
        $public = $this->post($author, 'public');
        $friends = $this->post($author, 'amis', PostVisibility::Friends);
        $private = $this->group($author, 'secret', GroupVisibility::Private);
        $groupPost = $this->post($author, 'prive', group: $private);

        $ids = array_column($this->api('GET', '/api/posts')['items'], 'id');
        self::assertContains((string) $public->getId(), $ids);
        self::assertNotContains((string) $friends->getId(), $ids);
        self::assertNotContains((string) $groupPost->getId(), $ids);

        $this->api('GET', '/api/posts/bonjour-le-monde-' . $public->getId());
        $this->api('GET', '/api/posts/bonjour-le-monde-' . $friends->getId(), expected: 404);
        $this->api('GET', '/api/posts/bonjour-le-monde-' . $groupPost->getId(), expected: 404);
    }

    public function testFriendsOnlyPostIsVisibleToFriendsOnly(): void
    {
        $author = $this->user('clara');
        $friend = $this->user('sam');
        $stranger = $this->user('noor');
        $this->befriend($author, $friend);
        $post = $this->post($author, 'amis', PostVisibility::Friends);

        // Réservée aux amis : dans le flux « Réseau », jamais dans « Récents » (publications publiques et groupes)
        $this->loginAs($friend);
        self::assertNotContains((string) $post->getId(), array_column($this->api('GET', '/api/posts')['items'], 'id'));
        self::assertContains((string) $post->getId(), array_column($this->api('GET', '/api/posts?feed=friends')['items'], 'id'));
        $this->api('GET', '/api/posts/bonjour-le-monde-' . $post->getId());

        $this->loginAs($stranger);
        self::assertNotContains((string) $post->getId(), array_column($this->api('GET', '/api/posts')['items'], 'id'));
        $this->api('POST', '/api/posts/' . $post->getId() . '/like', expected: 404);
    }

    public function testPrivateAccountPostsAreHiddenFromStrangers(): void
    {
        $author = $this->user('discret', isPrivate: true);
        $friend = $this->user('sam');
        $this->befriend($author, $friend);
        $post = $this->post($author);

        self::assertNotContains((string) $post->getId(), array_column($this->api('GET', '/api/posts')['items'], 'id'));
        $this->api('GET', '/api/posts/x-' . $post->getId(), expected: 404);

        $this->loginAs($friend);
        $this->api('GET', '/api/posts/x-' . $post->getId());
    }

    public function testPublicGroupOpenToAnyLoggedUserForLikesButPostingForMembers(): void
    {
        $owner = $this->user('clara');
        $outsider = $this->user('sam');
        $group = $this->group($owner, 'photographie');
        $post = $this->post($owner, group: $group);

        $this->loginAs($outsider);
        self::assertTrue($this->api('POST', '/api/posts/' . $post->getId() . '/like')['liked']);
        $this->api('POST', '/api/posts/' . $post->getId() . '/comments', ['content' => 'Bravo'], 201);
        $this->api('POST', '/api/posts', ['content' => 'Intrus', 'groupSlug' => 'photographie'], 403);

        $this->api('POST', '/api/groups/photographie/join');
        $this->api('POST', '/api/posts', ['content' => 'Me voilà', 'groupSlug' => 'photographie'], 201);
    }

    public function testPrivateGroupRequiresValidatedMembership(): void
    {
        $owner = $this->user('clara');
        $candidate = $this->user('sam');
        $group = $this->group($owner, 'cercle', GroupVisibility::Private);
        $post = $this->post($owner, group: $group);

        $this->loginAs($candidate);
        self::assertFalse($this->api('GET', '/api/groups/cercle')['canSeeContent']);
        $this->api('GET', '/api/groups/cercle/posts', expected: 404);
        $this->api('GET', '/api/posts/x-' . $post->getId(), expected: 404);

        self::assertSame('pending', $this->api('POST', '/api/groups/cercle/join')['membership']);
        $this->api('GET', '/api/groups/cercle/posts', expected: 404);
        $this->api('POST', '/api/groups/cercle/members/sam/approve', expected: 403);

        $this->loginAs($owner);
        $this->api('POST', '/api/groups/cercle/members/sam/approve', expected: 204);

        $this->loginAs($candidate);
        self::assertCount(1, $this->api('GET', '/api/groups/cercle/posts')['items']);
        $this->api('POST', '/api/posts/' . $post->getId() . '/like');
    }

    public function testLikeIsAToggleAndCountsStayExact(): void
    {
        $author = $this->user('clara');
        $post = $this->post($author);
        $uri = '/api/posts/' . $post->getId() . '/like';

        $this->loginAs($author);
        self::assertSame(['liked' => true, 'likesCount' => 1], $this->api('POST', $uri));
        self::assertSame(['liked' => false, 'likesCount' => 0], $this->api('POST', $uri));
    }

    public function testViewIsDeduplicatedAndReadingDoesNotCount(): void
    {
        $author = $this->user('clara');
        $reader = $this->user('sam');
        $post = $this->post($author);

        $this->api('GET', '/api/posts/x-' . $post->getId());
        self::assertSame(0, $this->reload($post)->getViewsCount());

        $this->loginAs($reader);
        self::assertTrue($this->api('POST', '/api/posts/' . $post->getId() . '/view')['counted']);
        self::assertFalse($this->api('POST', '/api/posts/' . $post->getId() . '/view')['counted']);
        self::assertSame(1, $this->api('GET', '/api/posts/x-' . $post->getId())['viewsCount']);
    }

    public function testReadOnlySanctionBlocksWritingButNotReading(): void
    {
        $author = $this->user('clara');
        $post = $this->post($author);
        $author->applySanction(UserStatus::ReadOnly, new \DateTimeImmutable('+1 day'));
        $this->em->flush();

        $this->loginAs($author);
        $this->api('GET', '/api/posts');
        $this->api('POST', '/api/posts', ['content' => 'Interdit'], 403);
        $this->api('POST', '/api/posts/' . $post->getId() . '/like', expected: 403);
    }

    public function testPostCreationExtractsHashtagsAndRejectsEmpty(): void
    {
        $this->loginAs($this->user('clara'));
        $this->api('POST', '/api/posts', ['content' => '   '], 400);

        $post = $this->api('POST', '/api/posts', ['content' => 'Sortie #Photo et #cafe, #photo encore'], 201);
        self::assertSame(['photo', 'cafe'], array_column($post['tags'], 'name'));
        self::assertMatchesRegularExpression('#^/post/sortie-photo-et-cafe-photo-encore-[0-9a-f-]{36}$#', $post['path']);
    }

    public function testPrivateMediaIsServedOnlyToAuthorizedUsersWithRangeSupport(): void
    {
        $owner = $this->user('clara');
        $this->group($owner, 'cercle', GroupVisibility::Private);

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n" . str_repeat('0', 2048) . "\n%%EOF");

        $this->loginAs($owner);
        $this->client->request(
            'POST',
            '/api/posts',
            ['content' => 'Document', 'groupSlug' => 'cercle'],
            ['attachments' => [new UploadedFile($path, 'plan.pdf', 'application/pdf', test: true)]]
        );
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $attachment = json_decode((string) $this->client->getResponse()->getContent(), true)['attachments'][0];
        self::assertFalse($attachment['isPublic']);
        self::assertStringContainsString('/api/media/', $attachment['url']);

        $uri = '/api/media/' . $attachment['id'];

        $this->client->request('GET', $uri, server: ['HTTP_RANGE' => 'bytes=0-99']);
        self::assertSame(206, $this->client->getResponse()->getStatusCode());
        self::assertSame('100', $this->client->getResponse()->headers->get('Content-Length'));

        $this->loginAs($this->user('sam'));
        $this->client->request('GET', $uri);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->loginAs(null);
        $this->client->request('GET', $uri);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testExecutableDisguisedAsImageIsRejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'php');
        file_put_contents($path, '<?php echo "pwned";');

        $this->loginAs($this->user('clara'));
        $this->client->request('POST', '/api/posts', ['content' => 'x'], ['attachments' => [new UploadedFile($path, 'photo.jpg', 'image/jpeg', test: true)]]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testDeletedPostDisappears(): void
    {
        $author = $this->user('clara');
        $post = $this->post($author);
        $post->setStatus(ContentStatus::Deleted);
        $this->em->flush();

        $this->api('GET', '/api/posts/x-' . $post->getId(), expected: 404);
    }
}
