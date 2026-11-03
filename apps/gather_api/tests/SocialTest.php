<?php

declare(strict_types=1);

namespace App\Tests;

use App\Command\RecalculatePopularityCommand;
use Fagathe\GatherCore\Dto\Input\ModerationDecisionInput;
use Fagathe\GatherCore\Entity\ModerationLog;
use Fagathe\GatherCore\Entity\Report;
use Fagathe\GatherCore\Enum\Auth\UserRole;
use Fagathe\GatherCore\Enum\Auth\UserStatus;
use Fagathe\GatherCore\Enum\Moderation\ModerationActionType;
use Fagathe\GatherCore\Enum\Moderation\ReportStatus;
use Fagathe\GatherCore\Enum\Social\ContentStatus;
use Fagathe\GatherCore\Service\LinkPreviewException;
use Fagathe\GatherCore\Service\LinkPreviewExtractorService;
use Fagathe\GatherCore\Service\ModerationService;
use Fagathe\GatherCore\Service\PopularityCalculatorService;
use Fagathe\GatherCore\Service\SearchService;

final class SocialTest extends ApiTestCase
{
    public function testFiveDistinctReportsHidePostAndDuplicateDoesNotCount(): void
    {
        $author = $this->user('clara');
        $post = $this->post($author);
        $body = ['targetType' => 'post', 'targetId' => (string) $post->getId(), 'reason' => 'spam'];

        $first = $this->user('reporter0');
        $this->loginAs($first);
        $this->api('POST', '/api/reports', $body, 201);
        $this->api('POST', '/api/reports', $body);

        for ($i = 1; $i <= 3; ++$i) {
            $this->loginAs($this->user('reporter' . $i));
            $this->api('POST', '/api/reports', $body, 201);
        }
        self::assertSame(ContentStatus::Published, $this->reload($post)->getStatus());

        $this->loginAs($this->user('reporter4'));
        $this->api('POST', '/api/reports', $body, 201);
        $post = $this->reload($post);
        self::assertSame(ContentStatus::AutoHidden, $post->getStatus());
        self::assertSame(5, $post->getReportsCount());

        $this->loginAs(null);
        $this->api('GET', '/api/posts/x-' . $post->getId(), expected: 404);
    }

    public function testModerationDecisionsApplySanctionsAndLog(): void
    {
        $author = $this->user('clara');
        $moderator = $this->user('modo', role: UserRole::ROLE_MODERATOR);
        $post = $this->post($author);
        $report = new Report($this->user('sam'), $post, \Fagathe\GatherCore\Enum\Moderation\ReportReason::Spam);
        $this->em->persist($report);
        $this->em->flush();

        /** @var ModerationService $service */
        $service = static::getContainer()->get(ModerationService::class);

        self::assertNotNull($service->resolve($report, $moderator, new ModerationDecisionInput(ModerationActionType::Suspension, 'Motif', 2)));
        self::assertSame(UserStatus::Active, $author->getStatus());

        self::assertNull($service->resolve($report, $moderator, new ModerationDecisionInput(ModerationActionType::Suspension, 'Motif', 7)));
        self::assertSame(UserStatus::Suspended, $author->getEffectiveStatus());
        self::assertSame(ReportStatus::Resolved, $report->getStatus());

        $log = $this->em->getRepository(ModerationLog::class)->findOneBy([]);
        self::assertSame(ModerationActionType::Suspension, $log->getActionType());
        self::assertSame(7, $log->getDurationDays());
    }

    public function testBooleanQueryIsSanitized(): void
    {
        self::assertSame('+photo* +argentique*', SearchService::toBooleanQuery('photo argentique'));
        self::assertSame('+photo*', SearchService::toBooleanQuery('+photo -- "()" ab'));
        self::assertNull(SearchService::toBooleanQuery('ab'));
        self::assertNull(SearchService::toBooleanQuery('() ab'));
    }

    public function testSearchRespectsPrivacyAndGroupVisibility(): void
    {
        $this->user('clarapublique');
        $hidden = $this->user('claradiscrete');
        $hidden->setIsExcludedFromSearch(true);
        $friend = $this->user('sam');
        $this->befriend($hidden, $friend);
        $this->group($friend, 'photo-privee', \Fagathe\GatherCore\Enum\Social\GroupVisibility::Private);

        foreach (['cla', 'clara'] as $q) {
            $usernames = array_column($this->api('GET', '/api/search?type=users&q=' . $q)['users'], 'username');
            self::assertContains('clarapublique', $usernames, $q);
            self::assertNotContains('claradiscrete', $usernames, $q);
        }

        $this->loginAs($friend);
        self::assertContains('claradiscrete', array_column($this->api('GET', '/api/search?type=users&q=cl')['users'], 'username'));
        self::assertContains('photo-privee', array_column($this->api('GET', '/api/search?type=groups&q=ph')['groups'], 'slug'));

        $this->loginAs(null);
        self::assertSame([], $this->api('GET', '/api/search?type=groups&q=ph')['groups']);
    }

    public function testPopularityFormula(): void
    {
        $post = $this->post($this->user('clara'));
        $connection = $this->em->getConnection();
        $connection->executeStatement(
            'UPDATE post SET views_count = 10, likes_count = 2, comments_count = 1, created_at = NOW() - INTERVAL 2 HOUR WHERE id = ?',
            [$post->getId()->toBinary()]
        );

        static::getContainer()->get(PopularityCalculatorService::class)->recalculatePosts();
        $this->em->refresh($post);

        // (10 × 0,1 + 2 + 1 × 3) / (2 + 2)^1,5 = 6 / 8
        self::assertEqualsWithDelta(0.75, $post->getPopularityScore(), 0.001);
    }

    public function testTagTrendingIgnoresPrivateContent(): void
    {
        $author = $this->user('clara');
        $this->loginAs($author);
        $this->api('POST', '/api/posts', ['content' => 'Public #soleil'], 201);
        $this->api('POST', '/api/posts', ['content' => 'Amis #secret', 'visibility' => 'friends'], 201);

        static::getContainer()->get(PopularityCalculatorService::class)->recalculateTags();

        $names = array_column($this->api('GET', '/api/discover')['tags'], 'name');
        self::assertContains('soleil', $names);
        self::assertNotContains('secret', $names);
    }

    public function testSsrfTargetsAreRefused(): void
    {
        /** @var LinkPreviewExtractorService $extractor */
        $extractor = static::getContainer()->get(LinkPreviewExtractorService::class);

        foreach ([
            'http://127.0.0.1/admin',
            'http://localhost/',
            'http://10.0.0.5/',
            'http://192.168.1.1/',
            'http://169.254.169.254/latest/meta-data/',
            'http://[::1]/',
            'http://[::ffff:10.0.0.1]/',
            'http://0.0.0.0/',
            'ftp://example.com/',
            'file:///etc/passwd',
            'http://user:pass@example.com/',
            'http://example.com:8080/',
        ] as $url) {
            try {
                $extractor->assertSafeUrl($url);
                self::fail('Devrait être refusé : ' . $url);
            } catch (LinkPreviewException) {
                self::assertTrue(true);
            }
        }

        self::assertTrue(LinkPreviewExtractorService::isPublicIp('93.184.216.34'));
        self::assertFalse(LinkPreviewExtractorService::isPublicIp('::ffff:192.168.0.1'));
    }

    public function testMessagingTracksUnreadAndIsolatesConversations(): void
    {
        $clara = $this->user('clara');
        $sam = $this->user('sam');
        $noor = $this->user('noor');

        $this->loginAs($clara);
        $conversation = $this->api('POST', '/api/conversations', ['usernames' => ['sam']], 201);
        $again = $this->api('POST', '/api/conversations', ['usernames' => ['sam']]);
        self::assertSame($conversation['id'], $again['id']);

        $this->api('POST', '/api/conversations/' . $conversation['id'] . '/messages', ['content' => 'Salut'], 201);
        $this->api('POST', '/api/conversations/' . $conversation['id'] . '/messages', ['content' => 'Ça va ?'], 201);

        $this->loginAs($sam);
        self::assertSame(2, $this->api('GET', '/api/me/counters')['unreadMessages']);
        self::assertCount(2, $this->api('GET', '/api/conversations/' . $conversation['id'] . '/messages')['items']);
        self::assertSame(0, $this->api('GET', '/api/me/counters')['unreadMessages']);

        $this->loginAs($noor);
        $this->api('GET', '/api/conversations/' . $conversation['id'] . '/messages', expected: 404);
    }

    public function testPrivateAccountCannotBeContactedByStrangers(): void
    {
        $this->user('discret', isPrivate: true);
        $this->loginAs($this->user('sam'));
        $this->api('POST', '/api/conversations', ['usernames' => ['discret']], 400);
    }

    public function testFriendRequestFlowAndProfilePrivacy(): void
    {
        $clara = $this->user('clara', isPrivate: true);
        $clara->setBio('Ma biographie');
        $sam = $this->user('sam');
        $this->em->flush();

        $this->loginAs($sam);
        self::assertNull($this->api('GET', '/api/users/clara')['bio']);
        self::assertSame('request_sent', $this->api('POST', '/api/friends/clara')['status']);

        $this->loginAs($clara);
        self::assertSame(1, $this->api('GET', '/api/me/counters')['friendRequests']);
        $this->api('POST', '/api/friends/sam/accept');

        $this->loginAs($sam);
        self::assertSame('Ma biographie', $this->api('GET', '/api/users/clara')['bio']);
        self::assertSame('friends', $this->api('GET', '/api/users/clara')['relation']);
    }

    public function testInternalNotesAreNeverExposedToMembers(): void
    {
        $member = $this->user('clara');
        $other = $this->user('sam');
        $staff = $this->user('modo', role: UserRole::ROLE_MODERATOR);

        $this->loginAs($member);
        $ticket = $this->api('POST', '/api/support/tickets', ['category' => 'bug_report', 'subject' => 'Bug', 'message' => 'Ça plante'], 201);
        self::assertMatchesRegularExpression('/^#TCK-\d{4}-[0-9A-F]{4}$/', $ticket['reference']);

        $entity = $this->em->find(\Fagathe\GatherCore\Entity\SupportTicket::class, $ticket['id']);
        $service = static::getContainer()->get(\Fagathe\GatherCore\Service\SupportService::class);
        $service->staffReply($entity, $staff, 'Note confidentielle', true, null);
        $service->staffReply($entity, $staff, 'Nous regardons', false, null);

        $contents = array_column($this->api('GET', '/api/support/tickets/' . $ticket['id'])['messages'], 'content');
        self::assertSame(['Ça plante', 'Nous regardons'], $contents);

        $this->loginAs($other);
        $this->api('GET', '/api/support/tickets/' . $ticket['id'], expected: 404);
    }

    public function testBookmarksAndCollections(): void
    {
        $author = $this->user('clara');
        $post = $this->post($author);

        $this->loginAs($author);
        self::assertTrue($this->api('POST', '/api/posts/' . $post->getId() . '/bookmark')['bookmarked']);
        $this->api('PATCH', '/api/posts/' . $post->getId() . '/bookmark', ['collectionName' => 'Idées']);

        $list = $this->api('GET', '/api/bookmarks');
        self::assertCount(1, $list['items']);
        self::assertTrue($list['items'][0]['bookmarkedByMe']);
        self::assertSame('Idées', $list['collections'][0]['name']);

        $this->loginAs(null);
        self::assertSame([], $this->api('GET', '/api/users/clara/collections/' . rawurlencode('Idées'))['items']);

        $this->loginAs($author);
        $this->api('PATCH', '/api/posts/' . $post->getId() . '/bookmark', ['collectionName' => 'Idées', 'isPublic' => true]);
        $this->loginAs(null);
        self::assertCount(1, $this->api('GET', '/api/users/clara/collections/' . rawurlencode('Idées'))['items']);
    }

    public function testSitemapExcludesPrivateContent(): void
    {
        $this->user('discret', isPrivate: true);
        $this->group($this->user('clara'), 'cercle', \Fagathe\GatherCore\Enum\Social\GroupVisibility::Private);

        $data = $this->api('GET', '/api/sitemap');
        self::assertContains('/u/clara', array_column($data['users'], 'path'));
        self::assertNotContains('/u/discret', array_column($data['users'], 'path'));
        self::assertNotContains('/g/cercle', array_column($data['groups'], 'path'));
    }
}
