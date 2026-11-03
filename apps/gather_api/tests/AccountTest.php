<?php

declare(strict_types=1);

namespace App\Tests;

use Fagathe\GatherCore\Entity\Bookmark;
use Fagathe\GatherCore\Entity\Comment;
use Fagathe\GatherCore\Entity\Conversation;
use Fagathe\GatherCore\Entity\ConversationParticipant;
use Fagathe\GatherCore\Entity\GroupMember;
use Fagathe\GatherCore\Entity\Message;
use Fagathe\GatherCore\Entity\Post;
use Fagathe\GatherCore\Entity\PostAttachment;
use Fagathe\GatherCore\Entity\PostLike;
use Fagathe\GatherCore\Entity\SocialGroup;
use Fagathe\GatherCore\Entity\SupportTicket;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Entity\UserRequest;
use Fagathe\GatherCore\Enum\Auth\UserRequestTypeEnum;
use Fagathe\GatherCore\Enum\Auth\UserRole;
use Fagathe\GatherCore\Enum\Auth\UserStatus;
use Fagathe\GatherCore\Enum\Social\AttachmentType;
use Fagathe\GatherCore\Enum\Social\GroupRole;
use Fagathe\GatherCore\Enum\Support\TicketCategory;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AccountTest extends ApiTestCase
{
    private function login(string $username): array
    {
        return $this->api('POST', '/api/auth/login', ['username' => $username, 'password' => base64_encode(self::PASSWORD)]);
    }

    private function pendingRequest(User $user, UserRequestTypeEnum $type): UserRequest
    {
        $this->em->clear();

        return $this->em->getRepository(UserRequest::class)->findOneBy(['user' => $user->getId(), 'type' => $type, 'isUsed' => false]);
    }

    private function wrongCode(string $code): string
    {
        return $code === '100000' ? '100001' : '100000';
    }

    // ─── Connexion par code ─────────────────────────────────────────────────

    public function testLoginWithCodeOpensASessionAndTheCodeIsSingleUse(): void
    {
        $user = $this->user('clara');

        $sent = $this->api('POST', '/api/auth/login-code', ['username' => 'clara']);
        self::assertSame(90, $sent['expiresIn']);
        self::assertEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage(), 'To', 'clara@gather.test');

        $code = $this->pendingRequest($user, UserRequestTypeEnum::OtpLogin)->getCode();
        self::assertEmailHtmlBodyContains(self::getMailerMessage(), (string) $code);

        $data = $this->api('POST', '/api/auth/login-code/verify', ['username' => 'clara', 'code' => $code]);
        self::assertIsString($data['token']);
        self::assertSame('clara', $data['user']['username']);
        self::assertNotNull($this->client->getCookieJar()->get('REFRESH_TOKEN', '/api'));
        self::assertSame('clara', $this->api('GET', '/api/me')['username']);

        $this->client->getCookieJar()->clear();
        $this->api('POST', '/api/auth/login-code/verify', ['username' => 'clara', 'code' => $code], 401);
    }

    public function testLoginCodeRequestDoesNotRevealWhetherAnAccountExists(): void
    {
        $this->user('clara');

        $known = $this->api('POST', '/api/auth/login-code', ['username' => 'clara']);
        self::assertEmailCount(1);

        $unknown = $this->api('POST', '/api/auth/login-code', ['username' => 'personne']);
        self::assertEmailCount(0);
        self::assertSame($known, $unknown);
    }

    public function testLoginCodeIsRevokedAfterTooManyWrongAttempts(): void
    {
        $user = $this->user('clara');
        $this->api('POST', '/api/auth/login-code', ['username' => 'clara']);
        $code = $this->pendingRequest($user, UserRequestTypeEnum::OtpLogin)->getCode();
        $wrong = $this->wrongCode((string) $code);

        for ($i = 0; $i < 4; ++$i) {
            $this->api('POST', '/api/auth/login-code/verify', ['username' => 'clara', 'code' => $wrong], 401);
        }
        $this->api('POST', '/api/auth/login-code/verify', ['username' => 'clara', 'code' => $wrong], 429);

        // Révoqué : même le bon code ne passe plus
        $this->api('POST', '/api/auth/login-code/verify', ['username' => 'clara', 'code' => $code], 429);
        self::assertNull($this->client->getCookieJar()->get('REFRESH_TOKEN', '/api'));
    }

    public function testSanctionedAccountCannotLoginWithACode(): void
    {
        $user = $this->user('clara');
        $this->api('POST', '/api/auth/login-code', ['username' => 'clara']);
        $code = $this->pendingRequest($user, UserRequestTypeEnum::OtpLogin)->getCode();

        $user = $this->reload($user);
        $user->applySanction(UserStatus::Banned);
        $this->em->flush();

        $response = $this->api('POST', '/api/auth/login-code/verify', ['username' => 'clara', 'code' => $code], 403);
        self::assertArrayNotHasKey('token', $response);
        self::assertNull($this->client->getCookieJar()->get('REFRESH_TOKEN', '/api'));
    }

    // ─── Changement d'e-mail ────────────────────────────────────────────────

    public function testEmailChangesOnlyAfterTheLinkAndTheSessionSurvives(): void
    {
        $user = $this->user('clara');
        $this->login('clara');

        $this->api('PATCH', '/api/me/email', ['email' => 'nouvelle@gather.test']);
        self::assertEmailAddressContains(self::getMailerMessage(), 'To', 'nouvelle@gather.test');
        self::assertSame('clara@gather.test', $this->api('GET', '/api/me')['email']);

        $token = $this->pendingRequest($user, UserRequestTypeEnum::EmailChange)->getToken();
        $confirmed = $this->api('POST', '/api/auth/confirm-email-change', ['token' => $token]);
        self::assertSame('nouvelle@gather.test', $confirmed['user']['email']);

        // Le JWT porte le nom d'utilisateur, le refresh token l'e-mail : les deux doivent continuer à fonctionner
        self::assertSame('nouvelle@gather.test', $this->api('GET', '/api/me')['email']);
        $this->api('POST', '/api/auth/refresh');
        self::assertSame('nouvelle@gather.test', $this->api('GET', '/api/me')['email']);
        self::assertCount(1, $this->api('GET', '/api/me/sessions')['items']);

        $this->api('POST', '/api/auth/confirm-email-change', ['token' => $token], 400);
        $this->client->getCookieJar()->clear();
        $this->login('nouvelle@gather.test');
        $this->client->getCookieJar()->clear();
        $this->api('POST', '/api/auth/login', ['username' => 'clara@gather.test', 'password' => base64_encode(self::PASSWORD)], 401);
    }

    public function testEmailChangeRefusesAnAddressAlreadyTaken(): void
    {
        $user = $this->user('clara');
        $this->user('sam');
        $this->loginAs($user);

        $this->api('PATCH', '/api/me/email', ['email' => 'sam@gather.test'], 400);
        $this->api('PATCH', '/api/me/email', ['email' => 'clara@gather.test'], 400);
        $this->api('PATCH', '/api/me/email', ['email' => 'pas-une-adresse'], 400);
        self::assertEmailCount(0);
    }

    public function testEmailChangeIsRefusedIfTheAddressWasTakenInTheMeantime(): void
    {
        $user = $this->user('clara');
        $this->loginAs($user);
        $this->api('PATCH', '/api/me/email', ['email' => 'prise@gather.test']);
        $token = $this->pendingRequest($user, UserRequestTypeEnum::EmailChange)->getToken();

        $this->user('prise');

        $this->api('POST', '/api/auth/confirm-email-change', ['token' => $token], 409);
        self::assertSame('clara@gather.test', $this->reload($user)->getEmail());
    }

    // ─── Suppression de compte ──────────────────────────────────────────────

    public function testAccountDeletionRequiresTheEmailedCode(): void
    {
        $user = $this->user('clara');
        $this->login('clara');

        $this->api('DELETE', '/api/me', ['code' => '123456'], 422);

        $this->api('POST', '/api/me/delete-request');
        self::assertEmailAddressContains(self::getMailerMessage(), 'To', 'clara@gather.test');
        $code = $this->pendingRequest($user, UserRequestTypeEnum::AccountDeletion)->getCode();

        $this->api('DELETE', '/api/me', ['code' => $this->wrongCode((string) $code)], 422);
        self::assertNotNull($this->reload($user));

        $this->client->request('DELETE', '/api/me', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['code' => $code]));
        $response = $this->client->getResponse();
        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());

        $cleared = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cleared[$cookie->getName()] = $cookie->isCleared();
        }
        self::assertSame(['BEARER' => true, 'REFRESH_TOKEN' => true], $cleared);

        $this->em->clear();
        self::assertNull($this->em->find(User::class, $user->getId()));
    }

    /** Sans le code valide, et avec l'ancienne session : plus aucun accès après la suppression. */
    public function testDeletionRevokesRefreshTokens(): void
    {
        $user = $this->user('clara');
        $this->login('clara');
        $refresh = $this->client->getCookieJar()->get('REFRESH_TOKEN', '/api')->getValue();

        $this->api('POST', '/api/me/delete-request');
        $code = $this->pendingRequest($user, UserRequestTypeEnum::AccountDeletion)->getCode();
        $this->api('DELETE', '/api/me', ['code' => $code], 204);

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM refresh_tokens'));
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('REFRESH_TOKEN', $refresh, path: '/api'));
        $this->api('POST', '/api/auth/refresh', expected: 401);
    }

    public function testDeletionRemovesContentAndKeepsCountersAccurateForOthers(): void
    {
        $clara = $this->user('clara');
        $sam = $this->user('sam');
        $noor = $this->user('noor');

        // Publication de clara, commentée par sam : disparaît entièrement
        $ownPost = $this->post($clara, 'Ma publication');
        $this->em->persist(new Comment($ownPost, $sam, 'Réponse de sam'));

        // Publication de sam, avec l'activité de clara : reste, compteurs recalculés
        $samPost = $this->post($sam, 'Publication de sam');
        $this->em->persist(new Comment($samPost, $clara, 'Commentaire de clara'));
        $this->em->persist(new Comment($samPost, $noor, 'Commentaire de noor'));
        $this->em->persist(new PostLike($samPost, $clara));
        $this->em->persist(new PostLike($samPost, $noor));
        $this->em->persist(new Bookmark($samPost, $clara));
        $this->em->flush();
        foreach (['comments' => 2, 'likes' => 2, 'bookmarks' => 1] as $counter => $value) {
            $this->em->getConnection()->executeStatement(sprintf('UPDATE post SET %s_count = %d WHERE id = ?', $counter, $value), [$samPost->getId()->toBinary()]);
        }

        // Tête-à-tête : disparaît. Conversation à trois : reste, sans les messages de clara
        $direct = $this->conversation($clara, $sam);
        $this->em->persist(new Message($direct, $sam, 'Message de sam'));
        $trio = $this->conversation($clara, $sam, $noor);
        $this->em->persist(new Message($trio, $clara, 'Message de clara'));
        $this->em->persist(new Message($trio, $noor, 'Message de noor'));

        $this->em->persist(new SupportTicket('GTR-TEST', $clara, TicketCategory::cases()[0], 'Mon ticket'));
        $this->em->flush();

        $this->deleteAccount($clara);
        $this->em->clear();

        self::assertNull($this->em->find(Post::class, $ownPost->getId()));
        self::assertNull($this->em->find(Conversation::class, $direct->getId()));
        self::assertNotNull($this->em->find(Conversation::class, $trio->getId()));
        self::assertSame(['Message de noor'], array_map(
            static fn(Message $message) => $message->getContent(),
            $this->em->getRepository(Message::class)->findBy(['conversation' => $trio->getId()])
        ));
        self::assertCount(0, $this->em->getRepository(SupportTicket::class)->findAll());

        $remaining = $this->em->find(Post::class, $samPost->getId());
        self::assertSame(1, $remaining->getCommentsCount());
        self::assertSame(1, $remaining->getLikesCount());
        self::assertSame(0, $remaining->getBookmarksCount());
    }

    public function testDeletionHandsCommunitiesOverOrRemovesThem(): void
    {
        $clara = $this->user('clara');
        $sam = $this->user('sam');
        $noor = $this->user('noor');

        $shared = $this->group($clara, 'partagee');
        $this->em->persist(new GroupMember($shared, $sam, GroupRole::Member));
        $this->em->persist(new GroupMember($shared, $noor, GroupRole::Moderator));
        $shared->setMembersCount(3);
        $alone = $this->group($clara, 'solitaire');
        $joined = $this->group($sam, 'celle-de-sam');
        $this->em->persist(new GroupMember($joined, $clara, GroupRole::Member));
        $joined->setMembersCount(2);
        $this->em->flush();

        $this->deleteAccount($clara);
        $this->em->clear();

        self::assertNull($this->em->find(SocialGroup::class, $alone->getId()));

        $shared = $this->em->find(SocialGroup::class, $shared->getId());
        self::assertSame('noor', $shared->getOwner()->getUsername());
        self::assertSame(2, $shared->getMembersCount());
        $membership = $this->em->getRepository(GroupMember::class)->findOneBy(['group' => $shared, 'user' => $noor->getId()]);
        self::assertSame(GroupRole::Owner, $membership->getRole());

        self::assertSame(1, $this->em->find(SocialGroup::class, $joined->getId())->getMembersCount());
    }

    public function testDeletionErasesUploadedImages(): void
    {
        $user = $this->user('clara');
        $this->loginAs($user);

        $avatar = $this->uploadImage('/api/me/avatar');
        $banner = $this->uploadImage('/api/me/banner');
        self::assertFileExists($avatar);
        self::assertFileExists($banner);

        $this->deleteAccount($user);

        self::assertFileDoesNotExist($avatar);
        self::assertFileDoesNotExist($banner);
    }

    public function testDeletionErasesPostAttachmentsAndNotThoseOfOthers(): void
    {
        $clara = $this->user('clara');
        $sam = $this->user('sam');
        $directory = static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/posts/test-suppression';
        mkdir($directory, recursive: true);
        foreach (['clara.webp', 'clara-thumb.webp', 'sam.webp'] as $file) {
            touch($directory . '/' . $file);
        }

        $this->em->persist(new PostAttachment($this->post($clara), AttachmentType::Image, 'test-suppression/clara.webp', 'clara.webp', 'image/webp', 1, 0, 'test-suppression/clara-thumb.webp'));
        $this->em->persist(new PostAttachment($this->post($sam), AttachmentType::Image, 'test-suppression/sam.webp', 'sam.webp', 'image/webp', 1, 0));
        $this->em->flush();

        $this->deleteAccount($clara);

        self::assertFileDoesNotExist($directory . '/clara.webp');
        self::assertFileDoesNotExist($directory . '/clara-thumb.webp');
        self::assertFileExists($directory . '/sam.webp');

        unlink($directory . '/sam.webp');
        rmdir($directory);
    }

    public function testReplacingAnAvatarErasesThePreviousFile(): void
    {
        $this->loginAs($this->user('clara'));

        $first = $this->uploadImage('/api/me/avatar');
        $second = $this->uploadImage('/api/me/avatar');

        self::assertFileDoesNotExist($first);
        self::assertFileExists($second);
        unlink($second);
    }

    public function testStaffAccountsCannotDeleteThemselves(): void
    {
        $user = $this->user('modo', role: UserRole::ROLE_MODERATOR);
        $this->loginAs($user);

        $this->api('POST', '/api/me/delete-request', expected: 403);
        $this->api('DELETE', '/api/me', ['code' => '123456'], 403);
        self::assertEmailCount(0);
    }

    // ─── Profil ─────────────────────────────────────────────────────────────

    public function testBioIsSavedAndVisibleOnThePublicProfile(): void
    {
        $user = $this->user('clara');
        $this->loginAs($user);

        $this->api('PATCH', '/api/me/profile', ['username' => 'clara', 'bio' => 'Photographe amatrice']);

        self::assertSame('Photographe amatrice', $this->api('GET', '/api/me')['bio']);
        self::assertSame('Photographe amatrice', $this->api('GET', '/api/users/clara')['bio']);
        $this->api('PATCH', '/api/me/profile', ['username' => 'clara', 'bio' => str_repeat('a', 501)], 400);
    }

    private function conversation(User ...$users): Conversation
    {
        $conversation = new Conversation();
        $this->em->persist($conversation);
        foreach ($users as $user) {
            $this->em->persist(new ConversationParticipant($conversation, $user));
        }
        $this->em->flush();

        return $conversation;
    }

    private function deleteAccount(User $user): void
    {
        $this->loginAs($this->reload($user));
        $this->api('POST', '/api/me/delete-request');
        $code = $this->pendingRequest($user, UserRequestTypeEnum::AccountDeletion)->getCode();
        $this->api('DELETE', '/api/me', ['code' => $code], 204);
    }

    /** @return string Chemin du fichier enregistré */
    private function uploadImage(string $uri): string
    {
        $image = imagecreatetruecolor(32, 32);
        $source = (string) tempnam(sys_get_temp_dir(), 'gather');
        imagepng($image, $source);

        $this->client->request('POST', $uri, files: ['file' => new UploadedFile($source, 'image.png', 'image/png', null, true)]);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $path = (string) parse_url(json_decode((string) $response->getContent(), true)['url'], PHP_URL_PATH);

        return static::getContainer()->getParameter('kernel.project_dir') . '/public' . $path;
    }
}
