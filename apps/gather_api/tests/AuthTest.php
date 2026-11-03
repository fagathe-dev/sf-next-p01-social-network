<?php

declare(strict_types=1);

namespace App\Tests;

use Fagathe\GatherCore\Enum\Auth\UserStatus;
use Symfony\Component\BrowserKit\Cookie;

final class AuthTest extends ApiTestCase
{
    private function login(string $username): array
    {
        return $this->api('POST', '/api/auth/login', ['username' => $username, 'password' => base64_encode(self::PASSWORD)]);
    }

    public function testLoginReturnsJwtAndKeepsRefreshTokenOutOfTheBody(): void
    {
        $this->user('clara');
        $data = $this->login('clara');

        self::assertIsString($data['token']);
        self::assertArrayNotHasKey('refreshToken', $data);
        self::assertSame('clara', $data['user']['username']);

        $cookies = [];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }
        self::assertTrue($cookies['BEARER']->isHttpOnly());
        self::assertSame('lax', $cookies['BEARER']->getSameSite());
        self::assertTrue($cookies['REFRESH_TOKEN']->isHttpOnly());

        self::assertSame('clara', $this->api('GET', '/api/me')['username']);
    }

    /** Requêtes axios du front : le jeton stocké est envoyé en Authorization, sans aucun cookie. */
    public function testAuthorizationHeaderAuthenticatesWithoutCookies(): void
    {
        $this->user('clara');
        $token = $this->login('clara')['token'];
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/me');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testExpiredCookieDoesNotBlockLogin(): void
    {
        $this->user('clara');
        $this->client->getCookieJar()->set(new Cookie('BEARER', 'jeton.invalide.expire'));

        self::assertSame('clara', $this->login('clara')['user']['username']);
    }

    public function testRegistrationCannotSetPrivilegedFields(): void
    {
        $this->api('POST', '/api/auth/register', [
            'email' => 'pirate@gmail.com',
            'username' => 'pirate',
            'password' => base64_encode(self::PASSWORD),
            'birthDate' => '2000-01-01',
            'deactivatedAt' => '2020-01-01',
            'role' => 'ROLE_ADMIN',
            'isVerified' => true,
            'warningsCount' => 99,
        ], 201);

        $this->em->clear();
        $user = $this->em->getRepository(\Fagathe\GatherCore\Entity\User::class)->findOneBy(['username' => 'pirate']);
        self::assertContains('ROLE_USER', $user->getRoles());
        self::assertNotContains('ROLE_ADMIN', $user->getRoles());
        self::assertFalse($user->isVerified());
        self::assertSame(0, $user->getWarningsCount());
        self::assertFalse($user->isDeactivated());
        self::assertStringStartsWith('$argon2id$', (string) $user->getPassword());
    }

    public function testRefreshRotatesTokenAndOldOneIsRejected(): void
    {
        $this->user('clara');
        $this->login('clara');
        $old = $this->client->getCookieJar()->get('REFRESH_TOKEN', '/api')->getValue();

        $this->api('POST', '/api/auth/refresh');
        $new = $this->client->getCookieJar()->get('REFRESH_TOKEN', '/api')->getValue();
        self::assertNotSame($old, $new);

        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new Cookie('REFRESH_TOKEN', $old, path: '/api'));
        $this->api('POST', '/api/auth/refresh', expected: 401);
    }

    public function testSessionsCanBeListedAndRevoked(): void
    {
        $this->user('clara');
        $this->login('clara');

        $sessions = $this->api('GET', '/api/me/sessions')['items'];
        self::assertCount(1, $sessions);
        self::assertTrue($sessions[0]['isCurrent']);

        $this->api('DELETE', '/api/me/sessions/' . $sessions[0]['id'], expected: 204);
        $this->api('POST', '/api/auth/refresh', expected: 401);
    }

    public function testSuspendedAndBannedUsersAreBlocked(): void
    {
        $user = $this->user('clara');
        $user->applySanction(UserStatus::Suspended, new \DateTimeImmutable('+7 days'));
        $this->em->flush();
        $this->api('POST', '/api/auth/login', ['username' => 'clara', 'password' => base64_encode(self::PASSWORD)], 403);

        $user = $this->reload($user);
        $user->applySanction(UserStatus::Suspended, new \DateTimeImmutable('-1 day'));
        $this->em->flush();
        $this->login('clara');

        $user = $this->reload($user);
        $user->applySanction(UserStatus::Banned);
        $this->em->flush();
        $this->api('POST', '/api/auth/login', ['username' => 'clara', 'password' => base64_encode(self::PASSWORD)], 403);
    }

    public function testForeignOriginIsRefusedOnWrites(): void
    {
        $this->client->request('POST', '/api/auth/logout', server: ['HTTP_ORIGIN' => 'https://evil.example']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
}
