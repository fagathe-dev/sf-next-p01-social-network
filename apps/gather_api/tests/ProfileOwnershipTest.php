<?php

declare(strict_types=1);

namespace App\Tests;

use Fagathe\GatherCore\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Seul le titulaire d'un compte modifie son profil : les routes d'écriture ne désignent jamais un membre,
 * elles agissent sur le compte de la session.
 */
final class ProfileOwnershipTest extends ApiTestCase
{
    private function image(): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'gather');
        imagepng(imagecreatetruecolor(16, 16), $path);

        return new UploadedFile($path, 'image.png', 'image/png', null, true);
    }

    public function testEveryProfileWriteRequiresASession(): void
    {
        $clara = $this->user('clara');
        $clara->setBio('Bio de clara');
        $this->em->flush();
        $this->loginAs(null);

        $this->api('PATCH', '/api/me/profile', ['username' => 'clara', 'bio' => 'Piratée'], 401);
        $this->api('PATCH', '/api/me/privacy', ['isPrivate' => true], 401);
        $this->api('PATCH', '/api/me/email', ['email' => 'pirate@gather.test'], 401);
        $this->api('POST', '/api/me/deactivate', expected: 401);
        $this->api('POST', '/api/me/delete-request', expected: 401);
        foreach (['avatar', 'banner'] as $kind) {
            $this->client->request('POST', '/api/me/' . $kind, files: ['file' => $this->image()]);
            self::assertSame(401, $this->client->getResponse()->getStatusCode());
        }

        $this->em->clear();
        self::assertSame('Bio de clara', $this->em->find(User::class, $clara->getId())->getBio());
    }

    public function testAMemberCannotTargetAnotherAccount(): void
    {
        $clara = $this->user('clara');
        $clara->setBio('Bio de clara');
        $sam = $this->user('sam');
        $this->em->flush();
        $this->loginAs($this->reload($sam));

        // Identifiants d'un autre membre dans le corps de la requête : ignorés, ou refusés si le nom est déjà pris
        $this->api('PATCH', '/api/me/profile', ['id' => (string) $clara->getId(), 'username' => 'clara', 'bio' => 'Piratée'], 400);
        $this->api('PATCH', '/api/me/profile', ['id' => (string) $clara->getId(), 'email' => 'clara@gather.test', 'username' => 'sam', 'bio' => 'Bio de sam']);

        $this->em->clear();
        self::assertSame('Bio de clara', $this->em->find(User::class, $clara->getId())->getBio());
        self::assertSame('clara', $this->em->find(User::class, $clara->getId())->getUsername());
        self::assertSame('Bio de sam', $this->em->find(User::class, $sam->getId())->getBio());
        self::assertSame('sam@gather.test', $this->em->find(User::class, $sam->getId())->getEmail());
    }

    public function testAModeratorHasNoSpecialRightOnSomeoneElsesProfile(): void
    {
        $clara = $this->user('clara');
        $clara->setBio('Bio de clara');
        $this->em->flush();
        $this->loginAs($this->user('modo', role: \Fagathe\GatherCore\Enum\Auth\UserRole::ROLE_MODERATOR));

        $this->api('PATCH', '/api/me/profile', ['id' => (string) $clara->getId(), 'username' => 'modo', 'bio' => 'Bio du modo']);

        $this->em->clear();
        self::assertSame('Bio de clara', $this->em->find(User::class, $clara->getId())->getBio());
    }

    public function testRelationTellsTheFrontWhoOwnsTheProfile(): void
    {
        $clara = $this->user('clara');
        $this->user('sam');

        $this->loginAs($this->reload($clara));
        self::assertSame('self', $this->api('GET', '/api/users/clara')['relation']);
        self::assertSame('none', $this->api('GET', '/api/users/sam')['relation']);

        $this->loginAs(null);
        self::assertSame('none', $this->api('GET', '/api/users/clara')['relation']);
    }
}
