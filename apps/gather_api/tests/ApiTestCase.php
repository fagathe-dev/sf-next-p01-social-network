<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Fagathe\GatherCore\Entity\Friendship;
use Fagathe\GatherCore\Entity\GroupMember;
use Fagathe\GatherCore\Entity\Post;
use Fagathe\GatherCore\Entity\SocialGroup;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Auth\UserRole;
use Fagathe\GatherCore\Enum\Social\FriendshipStatus;
use Fagathe\GatherCore\Enum\Social\GroupRole;
use Fagathe\GatherCore\Enum\Social\GroupVisibility;
use Fagathe\GatherCore\Enum\Social\PostVisibility;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

abstract class ApiTestCase extends WebTestCase
{
    protected const PASSWORD = 'Gather2026';

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($connection->createSchemaManager()->listTableNames() as $table) {
            if ($table !== 'doctrine_migration_versions') {
                $connection->executeStatement('DELETE FROM `' . $table . '`');
            }
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function user(string $username, bool $isPrivate = false, UserRole $role = UserRole::ROLE_USER): User
    {
        $user = (new User())
            ->setEmail($username . '@gather.test')
            ->setUsername($username)
            ->setRole($role)
            ->setIsPrivate($isPrivate)
            ->setIsVerified(true);
        $user->setPassword(static::getContainer()->get('security.user_password_hasher')->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function befriend(User $a, User $b): void
    {
        $friendship = new Friendship($a, $b);
        $friendship->setStatus(FriendshipStatus::Accepted);
        $this->em->persist($friendship);
        $this->em->flush();
    }

    protected function group(User $owner, string $slug, GroupVisibility $visibility = GroupVisibility::Public): SocialGroup
    {
        $group = new SocialGroup(ucfirst($slug), $slug, $owner);
        $group->setVisibility($visibility)->setMembersCount(1);
        $this->em->persist($group);
        $this->em->persist(new GroupMember($group, $owner, GroupRole::Owner));
        $this->em->flush();

        return $group;
    }

    protected function post(User $author, string $content = 'Bonjour le monde', PostVisibility $visibility = PostVisibility::Public, ?SocialGroup $group = null): Post
    {
        $post = new Post($author, 'bonjour-le-monde', $content, $group !== null ? PostVisibility::Group : $visibility);
        $post->setGroup($group);
        $this->em->persist($post);
        $this->em->flush();

        return $post;
    }

    /**
     * Après une requête, l'EntityManager est réinitialisé : il faut recharger l'entité avant de la modifier.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    protected function reload(object $entity): object
    {
        return $this->em->find($entity::class, $entity->getId());
    }

    protected function loginAs(?User $user): void
    {
        $this->client->getCookieJar()->clear();
        if ($user !== null) {
            $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
            $this->client->getCookieJar()->set(new Cookie('BEARER', $token));
        }
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    protected function api(string $method, string $uri, ?array $body = null, int $expected = 200): array
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $body !== null ? (string) json_encode($body) : null);
        $response = $this->client->getResponse();
        self::assertSame($expected, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true) ?? [];
    }
}
