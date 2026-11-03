<?php

declare(strict_types=1);

namespace App\Tests;

use Fagathe\GatherCore\Entity\Comment;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Auth\UserStatus;

/**
 * Compte désactivé par son propriétaire, et âge minimum.
 */
final class AccountVisibilityTest extends ApiTestCase
{
    private function login(string $username, int $expected = 200): array
    {
        return $this->api('POST', '/api/auth/login', ['username' => $username, 'password' => base64_encode(self::PASSWORD)], $expected);
    }

    public function testDeactivatedAccountBecomesInvisibleToOthers(): void
    {
        $clara = $this->user('clara');
        $sam = $this->user('sam');
        $noor = $this->user('noor');
        $this->befriend($clara, $sam);
        $claraPost = $this->post($clara, 'Publication invisible');
        $samPost = $this->post($sam, 'Publication de sam');
        $this->em->persist(new Comment($samPost, $clara, 'Commentaire invisible'));
        $this->em->flush();

        $this->loginAs($this->reload($clara));
        $this->client->request('POST', '/api/me/deactivate');
        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        foreach ([$sam, $noor, null] as $viewer) {
            $this->loginAs($viewer !== null ? $this->reload($viewer) : null);

            $this->api('GET', '/api/users/clara', expected: 404);
            $this->api('GET', '/api/users/clara/posts', expected: 404);
            $this->api('GET', '/api/posts/' . $claraPost->getId(), expected: 404);

            $feed = json_encode($this->api('GET', '/api/posts'));
            self::assertStringNotContainsString('Publication invisible', (string) $feed);

            $search = $this->api('GET', '/api/search?q=clara');
            self::assertSame([], $search['users']);

            $comments = json_encode($this->api('GET', '/api/posts/' . $samPost->getId() . '/comments'));
            self::assertStringNotContainsString('Commentaire invisible', (string) $comments);
        }

        $this->loginAs($this->reload($sam));
        self::assertStringNotContainsString('clara', (string) json_encode($this->api('GET', '/api/friends')));

        $this->loginAs($this->reload($noor));
        self::assertStringNotContainsString('clara', (string) json_encode($this->api('GET', '/api/friends/suggestions')));
        $this->api('POST', '/api/conversations', ['usernames' => ['clara']], 400);

        $this->client->request('GET', '/api/sitemap');
        self::assertStringNotContainsString('/u/clara', (string) $this->client->getResponse()->getContent());
    }

    public function testDeactivationClosesSessionsAndLoginReactivates(): void
    {
        $this->user('clara');
        $this->login('clara');

        $this->client->request('POST', '/api/me/deactivate');
        $response = $this->client->getResponse();
        self::assertSame(204, $response->getStatusCode());
        $cleared = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cleared[$cookie->getName()] = $cookie->isCleared();
        }
        self::assertSame(['BEARER' => true, 'REFRESH_TOKEN' => true], $cleared);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM refresh_tokens'));

        $this->client->getCookieJar()->clear();
        $this->api('GET', '/api/users/clara', expected: 404);

        // Se reconnecter réactive le compte
        $this->login('clara');
        $this->client->getCookieJar()->clear();
        self::assertSame('clara', $this->api('GET', '/api/users/clara')['user']['username']);
    }

    public function testBannedAccountIsNotReactivatedByDeactivationFlow(): void
    {
        $user = $this->user('clara');
        $this->loginAs($user);
        $this->client->request('POST', '/api/me/deactivate');

        $user = $this->em->find(User::class, $user->getId());
        $user->applySanction(UserStatus::Banned);
        $this->em->flush();

        $this->client->getCookieJar()->clear();
        $this->login('clara', 403);
        $this->api('GET', '/api/users/clara', expected: 404);
    }

    public function testRegistrationRequiresAMinimumAge(): void
    {
        $register = fn(array $extra, int $expected) => $this->api('POST', '/api/auth/register', [
            'email' => 'nouveau' . random_int(1000, 9999) . '@gather.test',
            'username' => 'nouveau' . random_int(1000, 9999),
            'password' => base64_encode(self::PASSWORD),
            ...$extra,
        ], $expected);

        self::assertArrayHasKey('birthDate', $register([], 400)['violations'] ?? $register([], 400));
        $register(['birthDate' => (new \DateTimeImmutable('-14 years +1 day'))->format('Y-m-d')], 400);
        $register(['birthDate' => (new \DateTimeImmutable('+1 year'))->format('Y-m-d')], 400);
        $register(['birthDate' => (new \DateTimeImmutable('-14 years'))->format('Y-m-d')], 201);
    }

    public function testProfileCannotDeclareAnUnderageBirthDateNorRemoveIt(): void
    {
        $this->loginAs($this->user('clara'));

        $this->api('PATCH', '/api/me/profile', ['username' => 'clara', 'birthDate' => '2000-05-10']);
        $this->api('PATCH', '/api/me/profile', ['username' => 'clara', 'birthDate' => (new \DateTimeImmutable('-10 years'))->format('Y-m-d')], 400);
        $this->api('PATCH', '/api/me/profile', ['username' => 'clara', 'birthDate' => '']);

        self::assertStringStartsWith('2000-05-10', $this->api('GET', '/api/me')['birthDate']);
    }
}
