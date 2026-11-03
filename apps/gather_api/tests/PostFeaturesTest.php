<?php

declare(strict_types=1);

namespace App\Tests;

use Fagathe\GatherCore\Entity\Comment;
use Fagathe\GatherCore\Entity\Post;
use Fagathe\GatherCore\Entity\PostAttachment;
use Fagathe\GatherCore\Enum\Auth\UserRole;
use Fagathe\GatherCore\Enum\Social\AttachmentType;
use Fagathe\GatherCore\Enum\Social\GroupVisibility;
use Fagathe\GatherCore\Enum\Social\PostVisibility;

/**
 * Modification des publications et commentaires, republication, likes de commentaires,
 * notifications, onglet Activité et passage vers le back-office.
 */
final class PostFeaturesTest extends ApiTestCase
{
    private function ids(string $uri): array
    {
        return array_column($this->api('GET', $uri)['items'], 'id');
    }

    // ─── Modification ───────────────────────────────────────────────────────

    public function testOnlyTheAuthorEditsAPost(): void
    {
        $clara = $this->user('clara');
        $post = $this->post($clara, 'Texte #ancien');
        $id = (string) $post->getId();

        $this->loginAs($this->user('sam'));
        $this->api('PATCH', "/api/posts/$id", ['content' => 'Piraté'], 403);
        $this->loginAs($this->user('modo', role: UserRole::ROLE_MODERATOR));
        $this->api('PATCH', "/api/posts/$id", ['content' => 'Réécrit par le staff'], 403);
        $this->loginAs(null);
        $this->api('PATCH', "/api/posts/$id", ['content' => 'Anonyme'], 401);

        $this->loginAs($this->reload($clara));
        $card = $this->api('GET', "/api/posts/x-$id");
        self::assertTrue($card['canEdit']);
        self::assertNull($card['editedAt']);

        $edited = $this->api('PATCH', "/api/posts/$id", ['content' => 'Texte corrigé #nouveau', 'locationName' => 'Lyon, France', 'visibility' => 'friends']);
        self::assertSame('Texte corrigé #nouveau', $edited['content']);
        self::assertSame('friends', $edited['visibility']);
        self::assertSame('Lyon, France', $edited['locationName']);
        self::assertSame(['nouveau'], array_column($edited['tags'], 'name'));
        self::assertNotNull($edited['editedAt']);

        $this->api('PATCH', "/api/posts/$id", ['content' => '   '], 400);
        $this->api('PATCH', "/api/posts/$id", ['content' => 'x', 'visibility' => 'group'], 400);
    }

    public function testChangingTheAudienceMovesTheFilesAndRemovedFilesAreErased(): void
    {
        $clara = $this->user('clara');
        $post = $this->post($clara, 'Avec photos');
        $dir = static::getContainer()->getParameter('kernel.project_dir');
        $public = $dir . '/public/uploads/posts/test-edition';
        $private = $dir . '/var/private_media/test-edition';
        @mkdir($public, recursive: true);
        foreach (['garde.webp', 'garde-thumb.webp', 'retire.webp'] as $file) {
            touch("$public/$file");
        }
        $kept = new PostAttachment($post, AttachmentType::Image, 'test-edition/garde.webp', 'garde.webp', 'image/webp', 1, 0, 'test-edition/garde-thumb.webp');
        $removed = new PostAttachment($post, AttachmentType::Image, 'test-edition/retire.webp', 'retire.webp', 'image/webp', 1, 1);
        $post->addAttachment($kept)->addAttachment($removed);
        $this->em->persist($kept);
        $this->em->persist($removed);
        $this->em->flush();
        $this->em->clear();

        $this->loginAs($this->reload($clara));
        $edited = $this->api('PATCH', '/api/posts/' . $post->getId(), [
            'content' => 'Avec photos, pour les amis',
            'visibility' => 'friends',
            'removeAttachmentIds' => [(string) $removed->getId()],
        ]);

        self::assertCount(1, $edited['attachments']);
        self::assertStringStartsWith('/api/media/', $edited['attachments'][0]['url']);
        self::assertFileDoesNotExist("$public/retire.webp");
        self::assertFileDoesNotExist("$public/garde.webp");
        self::assertFileExists("$private/garde.webp");
        self::assertFileExists("$private/garde-thumb.webp");

        foreach (['garde.webp', 'garde-thumb.webp'] as $file) {
            unlink("$private/$file");
        }
        rmdir($private);
        rmdir($public);
    }

    // ─── Republication ──────────────────────────────────────────────────────

    public function testRepostRulesAndCounter(): void
    {
        $clara = $this->user('clara');
        $sam = $this->user('sam');
        $noor = $this->user('noor');
        $this->befriend($sam, $noor);
        $public = $this->post($clara, 'À partager');
        $friendsOnly = $this->post($clara, 'Entre amis', PostVisibility::Friends);
        $secret = $this->post($clara, 'Secret', group: $this->group($clara, 'secret', GroupVisibility::Private));
        $id = (string) $public->getId();

        $this->loginAs($this->reload($clara));
        $this->api('POST', "/api/posts/$id/repost", [], 403);

        $this->loginAs($this->reload($sam));
        $this->api('POST', '/api/posts/' . $friendsOnly->getId() . '/repost', [], 404);
        $this->api('POST', '/api/posts/' . $secret->getId() . '/repost', [], 404);

        $repost = $this->api('POST', "/api/posts/$id/repost", ['content' => 'À lire #partage'], 201);
        self::assertTrue($repost['isRepost']);
        self::assertSame('friends', $repost['visibility']);
        self::assertSame($id, $repost['originalPost']['id']);
        self::assertSame(1, $repost['originalPost']['repostsCount']);
        self::assertTrue($repost['originalPost']['repostedByMe']);
        $this->api('POST', "/api/posts/$id/repost", [], 409);
        // Republier une republication renvoie à l'original
        $this->api('POST', '/api/posts/' . $repost['id'] . '/repost', [], 409);

        // Visible des amis de sam dans « Réseau », jamais dans « Récents »
        $this->loginAs($this->reload($noor));
        self::assertContains($repost['id'], $this->ids('/api/posts?feed=friends'));
        self::assertNotContains($repost['id'], $this->ids('/api/posts'));

        $this->loginAs($this->reload($sam));
        $this->api('DELETE', "/api/posts/$id/repost", expected: 204);
        $this->em->clear();
        self::assertSame(0, $this->em->find(Post::class, $public->getId())->getRepostsCount());
    }

    public function testRepostOfADeletedPostStaysARepostWithoutContent(): void
    {
        $clara = $this->user('clara');
        $sam = $this->user('sam');
        $post = $this->post($clara, 'Bientôt supprimé');

        $this->loginAs($this->reload($sam));
        $repost = $this->api('POST', '/api/posts/' . $post->getId() . '/repost', [], 201);

        $this->loginAs($this->reload($clara));
        $this->api('DELETE', '/api/posts/' . $post->getId(), expected: 204);

        $this->loginAs($this->reload($sam));
        $card = $this->api('GET', '/api/posts/x-' . $repost['id']);
        self::assertTrue($card['isRepost']);
        self::assertNull($card['originalPost']);
    }

    // ─── Flux ───────────────────────────────────────────────────────────────

    public function testNetworkFeedShowsOnlyFriends(): void
    {
        $me = $this->user('clara');
        $friend = $this->user('sam');
        $stranger = $this->user('noor');
        $this->befriend($me, $friend);
        $fromFriend = $this->post($friend, 'Ami');
        $fromStranger = $this->post($stranger, 'Inconnu');
        $mine = $this->post($me, 'Moi');
        $group = $this->group($stranger, 'club');
        $this->em->persist(new \Fagathe\GatherCore\Entity\GroupMember($group, $me));
        $this->em->flush();
        $inGroup = $this->post($stranger, 'Club', group: $group);

        $this->loginAs($this->reload($me));
        $network = $this->ids('/api/posts?feed=friends');
        self::assertSame([(string) $fromFriend->getId()], $network);

        $recent = $this->ids('/api/posts');
        foreach ([$fromFriend, $fromStranger, $mine, $inGroup] as $post) {
            self::assertContains((string) $post->getId(), $recent);
        }
    }

    // ─── Commentaires ───────────────────────────────────────────────────────

    public function testCommentEditAndLike(): void
    {
        $clara = $this->user('clara');
        $sam = $this->user('sam');
        $post = $this->post($clara, 'Publication');

        $this->loginAs($this->reload($sam));
        $comment = $this->api('POST', '/api/posts/' . $post->getId() . '/comments', ['content' => 'Premier jet'], 201);

        $this->loginAs($this->reload($clara));
        $this->api('PATCH', '/api/comments/' . $comment['id'], ['content' => 'Réécrit'], 403);
        $liked = $this->api('POST', '/api/comments/' . $comment['id'] . '/like');
        self::assertSame(['liked' => true, 'likesCount' => 1], $liked);
        $listed = $this->api('GET', '/api/posts/' . $post->getId() . '/comments')['items'][0];
        self::assertTrue($listed['likedByMe']);
        self::assertSame(1, $listed['likesCount']);
        self::assertSame(['liked' => false, 'likesCount' => 0], $this->api('POST', '/api/comments/' . $comment['id'] . '/like'));

        $this->loginAs($this->reload($sam));
        $edited = $this->api('PATCH', '/api/comments/' . $comment['id'], ['content' => 'Version corrigée']);
        self::assertSame('Version corrigée', $edited['content']);
        self::assertNotNull($edited['editedAt']);
    }

    // ─── Notifications ──────────────────────────────────────────────────────

    public function testNotificationsAreCreatedGroupedAndRead(): void
    {
        $clara = $this->user('clara');
        $sam = $this->user('sam');
        $noor = $this->user('noor');
        $post = $this->post($clara, 'Ma photo');
        $id = (string) $post->getId();

        foreach ([$sam, $noor] as $fan) {
            $this->loginAs($this->reload($fan));
            $this->api('POST', "/api/posts/$id/like");
        }
        $this->loginAs($this->reload($sam));
        $comment = $this->api('POST', "/api/posts/$id/comments", ['content' => 'Bravo @noor'], 201);
        $this->api('POST', '/api/friends/clara');

        // Son propre like ne notifie pas
        $this->loginAs($this->reload($clara));
        $this->api('POST', "/api/posts/$id/like");

        $preview = $this->api('GET', '/api/notifications/preview');
        self::assertSame(3, $preview['unreadCount']);
        self::assertCount(3, $preview['items']);
        $liked = array_values(array_filter($this->api('GET', '/api/notifications')['items'], static fn($n) => $n['type'] === 'post_liked'))[0];
        self::assertSame(2, $liked['actorsCount']);
        self::assertMatchesRegularExpression('/^@noor et 1 autre ont aimé votre publication$/u', $liked['message']);
        self::assertSame("/post/bonjour-le-monde-$id", $liked['href']);

        $this->api('POST', '/api/notifications/' . $liked['id'] . '/read');
        self::assertSame(2, $this->api('GET', '/api/me/counters')['notifications']);
        self::assertCount(2, $this->api('GET', '/api/notifications?filter=unread')['items']);
        $this->api('POST', '/api/notifications/read-all', expected: 204);
        self::assertSame(0, $this->api('GET', '/api/me/counters')['notifications']);

        // Mentionnée dans un commentaire qu'elle peut lire
        $this->loginAs($this->reload($noor));
        $mention = array_values(array_filter($this->api('GET', '/api/notifications')['items'], static fn($n) => $n['type'] === 'user_mentioned'));
        self::assertCount(1, $mention);
        self::assertStringEndsWith('#comment-' . $comment['id'], $mention[0]['href']);

        // Les notifications d'un autre membre restent inaccessibles
        $this->api('POST', '/api/notifications/' . $liked['id'] . '/read', expected: 404);
    }

    public function testMentionInContentTheMemberCannotReadDoesNotNotify(): void
    {
        $clara = $this->user('clara');
        $this->user('noor');
        $this->loginAs($this->reload($clara));
        $this->api('POST', '/api/posts', ['content' => 'Pour mes amis seulement @noor', 'visibility' => 'friends'], 201);

        $this->loginAs($this->reload($this->em->getRepository(\Fagathe\GatherCore\Entity\User::class)->findOneBy(['username' => 'noor'])));
        self::assertSame([], $this->api('GET', '/api/notifications')['items']);
    }

    // ─── Activité ───────────────────────────────────────────────────────────

    public function testActivityIsStrictlyPersonal(): void
    {
        $clara = $this->user('clara');
        $sam = $this->user('sam');
        $post = $this->post($clara, 'Publication de clara');
        $id = (string) $post->getId();

        $this->loginAs($this->reload($sam));
        $this->api('POST', "/api/posts/$id/like");
        $comment = $this->api('POST', "/api/posts/$id/comments", ['content' => 'Mon commentaire'], 201);
        $this->api('POST', '/api/comments/' . $comment['id'] . '/like');
        $this->api('POST', "/api/posts/$id/view");
        $this->api('POST', "/api/posts/$id/repost", [], 201);

        $likes = $this->api('GET', '/api/me/activity/likes')['items'];
        self::assertEqualsCanonicalizing(['comment', 'post'], array_column($likes, 'kind'));
        $comments = $this->api('GET', '/api/me/activity/comments')['items'];
        self::assertSame('Mon commentaire', $comments[0]['content']);
        self::assertSame("/post/bonjour-le-monde-$id", $comments[0]['post']['path']);
        self::assertCount(1, $this->api('GET', '/api/me/activity/reposts')['items']);
        self::assertSame($id, $this->api('GET', '/api/me/activity/views')['items'][0]['post']['id']);

        // Clara ne voit jamais l'activité de sam, et aucun paramètre ne permet de la demander
        $this->loginAs($this->reload($clara));
        self::assertSame([], $this->api('GET', '/api/me/activity/likes?userId=' . $sam->getId() . '&username=sam')['items']);
        $this->loginAs(null);
        $this->api('GET', '/api/me/activity/likes', expected: 401);
    }

    public function testViewIsCountedOncePerMemberButDateRefreshed(): void
    {
        $post = $this->post($this->user('clara'), 'Lue deux fois');
        $this->loginAs($this->user('sam'));

        self::assertTrue($this->api('POST', '/api/posts/' . $post->getId() . '/view')['counted']);
        self::assertFalse($this->api('POST', '/api/posts/' . $post->getId() . '/view')['counted']);
        self::assertSame(1, $this->api('GET', '/api/posts/x-' . $post->getId())['viewsCount']);
    }

    // ─── Passage vers le back-office ────────────────────────────────────────

    public function testOnlyStaffObtainsAnAdminHandoffToken(): void
    {
        $this->loginAs($this->user('clara'));
        $this->api('POST', '/api/me/admin-handoff', expected: 403);

        $this->loginAs($this->user('modo', role: UserRole::ROLE_MODERATOR));
        $first = $this->api('POST', '/api/me/admin-handoff');
        self::assertSame(64, strlen($first['token']));
        self::assertSame(60, $first['expiresIn']);

        // Un nouveau jeton révoque le précédent
        $second = $this->api('POST', '/api/me/admin-handoff');
        $handoff = static::getContainer()->get(\Fagathe\GatherCore\Service\AdminHandoffService::class);
        self::assertNull($handoff->redeem($first['token']));
        self::assertSame('modo', $handoff->redeem($second['token'])?->getUsername());
        self::assertNull($handoff->redeem($second['token']));

        $this->loginAs(null);
        $this->api('POST', '/api/me/admin-handoff', expected: 401);
    }
}
