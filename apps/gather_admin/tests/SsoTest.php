<?php

declare(strict_types=1);

namespace App\Tests;

use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Entity\UserRequest;
use Fagathe\GatherCore\Enum\Auth\UserRequestTypeEnum;
use Fagathe\GatherCore\Enum\Auth\UserRole;
use Fagathe\GatherCore\Enum\Auth\UserStatus;
use Fagathe\GatherCore\Service\AdminHandoffService;
use Fagathe\GatherCore\Service\UserRequestService;

final class SsoTest extends AdminTestCase
{
    private function token(User $user): string
    {
        // Jeton émis directement : un membre ordinaire n'en obtient jamais par l'API
        return (string) static::getContainer()->get(UserRequestService::class)
            ->createRequest($user, UserRequestTypeEnum::AdminHandoff, isOtp: false, ttlSeconds: AdminHandoffService::TTL_SECONDS)
            ->getToken();
    }

    public function testStaffTokenOpensASessionOnceWithoutPassword(): void
    {
        $token = $this->token($this->user('modo', UserRole::ROLE_MODERATOR));

        $this->client->request('GET', '/sso?token=' . $token . '&target=/support/tickets');
        self::assertResponseRedirects('/support/tickets');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');

        $this->client->request('GET', '/moderation/reports');
        self::assertResponseIsSuccessful();

        // Usage unique : une seconde fois, même session fermée, le jeton ne vaut plus rien
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/sso?token=' . $token);
        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/moderation/reports');
        self::assertResponseRedirects();
    }

    public function testMemberOrSanctionedStaffNeverGetsIn(): void
    {
        $member = $this->user('clara');
        $this->client->request('GET', '/sso?token=' . $this->token($member));
        self::assertResponseRedirects('/login');

        $banned = $this->user('ancien', UserRole::ROLE_MODERATOR);
        $banned->applySanction(UserStatus::Banned);
        $this->em->flush();
        $this->client->request('GET', '/sso?token=' . $this->token($banned));
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/sso?token=nimportequoi');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/moderation/reports');
        self::assertResponseRedirects();
    }

    public function testExpiredTokenIsRefused(): void
    {
        $token = $this->token($this->user('modo', UserRole::ROLE_MODERATOR));
        $request = $this->em->getRepository(UserRequest::class)->findOneBy(['token' => $token]);
        $request->setExpiresAt(new \DateTimeImmutable('-1 second'));
        $this->em->flush();

        $this->client->request('GET', '/sso?token=' . $token);
        self::assertResponseRedirects('/login');
    }

    public function testTargetCannotLeaveTheBackOffice(): void
    {
        foreach (['//evil.example/x', 'https://evil.example', '/\\evil.example'] as $target) {
            $token = $this->token($this->user('modo' . random_int(100, 999), UserRole::ROLE_MODERATOR));
            $this->client->request('GET', '/sso?token=' . $token . '&target=' . urlencode($target));
            self::assertResponseRedirects('/moderation/reports');
            $this->client->getCookieJar()->clear();
        }
    }

    public function testLoginPageOffersTheApplicationConnection(): void
    {
        $this->client->request('GET', '/login');
        self::assertSelectorExists('a[href="/sso/start"]');

        $this->client->request('GET', '/sso/start?target=/support/tickets');
        self::assertResponseRedirects('http://localhost:3000/sso/admin?target=%2Fsupport%2Ftickets');
    }
}
