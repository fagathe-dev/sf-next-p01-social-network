<?php

declare(strict_types=1);

namespace App\Tests;

use Fagathe\GatherCore\Entity\Conversation;
use Fagathe\GatherCore\Entity\ConversationParticipant;
use Fagathe\GatherCore\Entity\Message;
use Fagathe\GatherCore\Entity\SupportMessage;
use Fagathe\GatherCore\Entity\SupportTicket;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Support\TicketCategory;
use Fagathe\GatherCore\Enum\Social\GroupVisibility;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Deux réglages distincts (compte privé, exclusion de la recherche), liste d'amis d'un profil,
 * fichiers d'une conversation et demandes de support des visiteurs.
 */
final class PrivacyAndFriendsTest extends ApiTestCase
{
    private function usernames(string $query): array
    {
        return array_column($this->api('GET', '/api/search?type=users&q=' . $query)['users'], 'username');
    }

    // ─── Confidentialité ────────────────────────────────────────────────────

    public function testPrivateAccountStaysFindableButShowsARestrictedProfile(): void
    {
        $clara = $this->user('clara', isPrivate: true);
        $clara->setBio('Ma biographie');
        $sam = $this->user('sam');
        $noor = $this->user('noor');
        $this->befriend($clara, $sam);
        $group = $this->group($clara, 'publique');
        $this->post($clara, 'Dans une communauté publique', group: $group);
        $this->post($clara, 'Réservé aux amis');

        // Ami d'un ami : trouve le compte, voit un profil restreint, aucun contenu
        $this->loginAs($this->reload($noor));
        self::assertContains('clara', $this->usernames('clara'));

        $profile = $this->api('GET', '/api/users/clara');
        self::assertFalse($profile['canSeeDetails']);
        self::assertNull($profile['bio']);
        self::assertNull($profile['friendsCount']);
        self::assertSame([], $this->api('GET', '/api/users/clara/posts')['items']);
        $this->api('GET', '/api/users/clara/friends', expected: 403);

        // Ami : tout est accessible
        $this->loginAs($this->reload($sam));
        $profile = $this->api('GET', '/api/users/clara');
        self::assertTrue($profile['canSeeDetails']);
        self::assertSame('Ma biographie', $profile['bio']);
        self::assertSame(1, $profile['friendsCount']);
        self::assertCount(2, $this->api('GET', '/api/users/clara/posts')['items']);
    }

    public function testExclusionFromSearchIsIndependentOfPrivateAccount(): void
    {
        $clara = $this->user('clara');
        $sam = $this->user('sam');
        $noor = $this->user('noor');
        $this->befriend($clara, $sam);

        $this->loginAs($this->reload($clara));
        self::assertSame(
            ['isPrivate' => false, 'isExcludedFromSearch' => true],
            $this->api('PATCH', '/api/me/privacy', ['isExcludedFromSearch' => true])
        );
        self::assertTrue($this->api('GET', '/api/me')['isExcludedFromSearch']);

        // Le profil reste public, mais introuvable par recherche, suggestions et sitemap
        $this->loginAs($this->reload($noor));
        self::assertNotContains('clara', $this->usernames('clara'));
        self::assertTrue($this->api('GET', '/api/users/clara')['canSeeDetails']);
        self::assertNotContains('clara', array_column($this->api('GET', '/api/friends/suggestions')['items'], 'username'));
        self::assertNotContains('/u/clara', array_column($this->api('GET', '/api/sitemap')['users'], 'path'));

        $this->loginAs(null);
        self::assertNotContains('clara', $this->usernames('clara'));

        // Un ami la retrouve toujours
        $this->loginAs($this->reload($sam));
        self::assertContains('clara', $this->usernames('clara'));
    }

    public function testPrivacySettingsAreChangedOneAtATimeAndValidated(): void
    {
        $this->loginAs($this->user('clara'));

        self::assertSame(['isPrivate' => true, 'isExcludedFromSearch' => false], $this->api('PATCH', '/api/me/privacy', ['isPrivate' => true]));
        self::assertSame(['isPrivate' => true, 'isExcludedFromSearch' => true], $this->api('PATCH', '/api/me/privacy', ['isExcludedFromSearch' => true]));
        self::assertSame(['isPrivate' => false, 'isExcludedFromSearch' => true], $this->api('PATCH', '/api/me/privacy', ['isPrivate' => false]));

        $this->api('PATCH', '/api/me/privacy', [], 400);
        $this->api('PATCH', '/api/me/privacy', ['isPrivate' => 'oui'], 400);
    }

    public function testRegistrationCannotSetSearchExclusion(): void
    {
        $this->api('POST', '/api/auth/register', [
            'email' => 'nouveau@gmail.com',
            'username' => 'nouveau',
            'password' => base64_encode(self::PASSWORD),
            'birthDate' => '2000-01-01',
            'isExcludedFromSearch' => true,
        ], 201);

        $this->em->clear();
        self::assertFalse($this->em->getRepository(User::class)->findOneBy(['username' => 'nouveau'])->isExcludedFromSearch());
    }

    // ─── Liste d'amis ───────────────────────────────────────────────────────

    public function testFriendListIncludesPrivateAccountsButNotHiddenOnes(): void
    {
        $clara = $this->user('clara');
        $this->befriend($clara, $this->user('prive', isPrivate: true));
        $this->befriend($clara, $this->user('public'));
        $gone = $this->user('parti');
        $this->befriend($clara, $gone);
        $gone->deactivate();
        $this->em->flush();

        $this->loginAs(null);
        $data = $this->api('GET', '/api/users/clara/friends');

        self::assertSame(['prive', 'public'], array_column($data['items'], 'username'));
        self::assertSame(2, $data['total']);
        self::assertFalse($data['hasMore']);
        self::assertSame(2, $this->api('GET', '/api/users/clara')['friendsCount']);
    }

    public function testFriendListIsPaginated(): void
    {
        $clara = $this->user('clara');
        for ($i = 10; $i < 45; ++$i) {
            $this->befriend($clara, $this->user('ami' . $i));
        }

        $this->loginAs(null);
        $first = $this->api('GET', '/api/users/clara/friends');
        self::assertCount(30, $first['items']);
        self::assertTrue($first['hasMore']);
        self::assertSame(35, $first['total']);

        $second = $this->api('GET', '/api/users/clara/friends?page=2');
        self::assertCount(5, $second['items']);
        self::assertFalse($second['hasMore']);
    }

    // ─── Messagerie ─────────────────────────────────────────────────────────

    public function testConversationExposesItsFilesAndSearchToParticipantsOnly(): void
    {
        $clara = $this->user('clara');
        $sam = $this->user('sam');
        $noor = $this->user('noor');
        $conversation = new Conversation();
        $this->em->persist($conversation);
        $conversation->addParticipant($clara);
        $conversation->addParticipant($sam);
        $this->em->persist(new Message($conversation, $sam, 'On se voit samedi ?'));
        $this->em->persist(new Message($conversation, $sam, 'Réduction de 100% incroyable'));
        $this->em->flush();
        $id = (string) $conversation->getId();

        $this->loginAs($this->reload($clara));

        $path = (string) tempnam(sys_get_temp_dir(), 'gather');
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
        $this->client->request('POST', "/api/conversations/$id/messages", ['content' => 'Le devis'], ['attachment' => new UploadedFile($path, 'devis été.pdf', 'application/pdf', null, true)]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $sent = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('devis été.pdf', $sent['attachment']['name']);
        self::assertSame('application/pdf', $sent['attachment']['mimeType']);

        $items = $this->api('GET', "/api/conversations/$id/attachments")['items'];
        self::assertCount(1, $items);
        self::assertSame($sent['attachment']['url'], $items[0]['attachment']['url']);

        $this->client->request('GET', $sent['attachment']['url']);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/pdf', (string) $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        self::assertSame(['On se voit samedi ?'], array_column($this->api('GET', "/api/conversations/$id/search?q=samedi")['items'], 'content'));
        self::assertSame([], $this->api('GET', "/api/conversations/$id/search?q=s")['items']);
        // Un % saisi est cherché tel quel, ce n'est pas un joker
        self::assertSame(['Réduction de 100% incroyable'], array_column($this->api('GET', "/api/conversations/$id/search?q=" . urlencode('100%'))['items'], 'content'));

        $this->loginAs($this->reload($noor));
        $this->api('GET', "/api/conversations/$id/attachments", expected: 404);
        $this->api('GET', "/api/conversations/$id/search?q=samedi", expected: 404);

        $stored = static::getContainer()->getParameter('kernel.project_dir') . '/var/private_media/messages';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stored, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getMTime() >= time() - 60) {
                unlink($file->getPathname());
            }
        }
    }

    // ─── Support ────────────────────────────────────────────────────────────

    public function testVisitorCanOpenATicketWithoutAccount(): void
    {
        $this->client->getCookieJar()->clear();
        $created = $this->api('POST', '/api/support/public', [
            'email' => 'Visiteur@Gather.test',
            'name' => 'Camille',
            'category' => 'account_issue',
            'subject' => 'Je ne peux pas me connecter',
            'message' => 'Le code ne marche pas',
            'pageUrl' => 'https://gather.example/login',
        ], 201);
        self::assertArrayHasKey('reference', $created);

        $this->em->clear();
        $ticket = $this->em->getRepository(SupportTicket::class)->findOneBy(['reference' => $created['reference']]);
        self::assertTrue($ticket->isGuest());
        self::assertSame('visiteur@gather.test', $ticket->getRequesterEmail());
        self::assertSame('Camille', $ticket->getRequesterName());
        self::assertSame('https://gather.example/login', $ticket->getPageUrl());
        self::assertNull($this->em->getRepository(SupportMessage::class)->findOneBy(['ticket' => $ticket])->getSender());
    }

    public function testPublicTicketIsValidatedAndRobotsAreDiscarded(): void
    {
        $payload = ['email' => 'visiteur@gather.test', 'category' => 'other', 'subject' => 'Sujet', 'message' => 'Message'];

        $this->api('POST', '/api/support/public', [...$payload, 'email' => 'pas-une-adresse'], 400);
        $this->api('POST', '/api/support/public', [...$payload, 'subject' => ''], 400);
        $this->api('POST', '/api/support/public', [...$payload, 'pageUrl' => 'javascript:alert(1)'], 400);

        $robot = $this->api('POST', '/api/support/public', [...$payload, 'website' => 'https://spam.example'], 201);
        self::assertArrayNotHasKey('reference', $robot);
        self::assertCount(0, $this->em->getRepository(SupportTicket::class)->findAll());
    }

    public function testMemberTicketKeepsTheConcernedUrl(): void
    {
        $this->loginAs($this->user('clara'));

        $ticket = $this->api('POST', '/api/support/tickets', [
            'category' => 'bug_report',
            'subject' => 'Page cassée',
            'message' => 'Elle ne charge pas',
            'pageUrl' => 'https://gather.example/g/photo',
        ], 201);

        self::assertSame('https://gather.example/g/photo', $ticket['pageUrl']);
        $this->api('POST', '/api/support/tickets', ['category' => 'bug_report', 'subject' => 'x', 'message' => 'y', 'pageUrl' => 'javascript:alert(1)'], 400);
    }

    public function testStaffReplyReachesAVisitorByEmailWithTheFullText(): void
    {
        $staff = $this->user('modo', role: \Fagathe\GatherCore\Enum\Auth\UserRole::ROLE_MODERATOR);
        $ticket = new SupportTicket('#TCK-2026-GUES', null, TicketCategory::Other, 'Question');
        $ticket->setGuest('visiteur@gmail.com', null);
        $this->em->persist($ticket);
        $this->em->flush();

        $long = str_repeat('Réponse complète. ', 40);
        static::getContainer()->get(\Fagathe\GatherCore\Service\SupportService::class)->staffReply($ticket, $staff, $long, false, null);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'To', 'visiteur@gmail.com');
        self::assertEmailHtmlBodyContains($email, trim($long));
        self::assertEmailHtmlBodyNotContains($email, '/support/');
    }

    public function testGroupVisibilityStillAppliesForFriendList(): void
    {
        // Garde-fou : la communauté privée d'un ami reste invisible, même via son profil
        $clara = $this->user('clara');
        $group = $this->group($clara, 'secret', GroupVisibility::Private);
        $this->post($clara, 'Message secret', group: $group);

        $this->loginAs($this->user('sam'));
        self::assertSame([], $this->api('GET', '/api/users/clara/posts')['items']);
    }
}
