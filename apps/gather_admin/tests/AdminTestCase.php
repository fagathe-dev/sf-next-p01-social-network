<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Auth\UserRole;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class AdminTestCase extends WebTestCase
{
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

    protected function user(string $username, UserRole $role = UserRole::ROLE_USER): User
    {
        $user = (new User())->setEmail($username . '@gather.test')->setUsername($username)->setRole($role)->setIsVerified(true);
        $user->setPassword(static::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'Gather2026'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Page réelle du back-office : passe par le formulaire et son jeton CSRF. */
    protected function submitLogin(string $username, string $password = 'Gather2026'): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['_username' => $username, '_password' => $password]));
    }
}
