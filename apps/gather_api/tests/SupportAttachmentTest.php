<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Fagathe\GatherCore\Entity\SupportMessage;
use Fagathe\GatherCore\Entity\SupportTicket;
use Fagathe\GatherCore\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SupportAttachmentTest extends ApiTestCase
{
    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        array_map(static fn(string $file) => is_file($file) && unlink($file), $this->temporary);
        parent::tearDown();
    }

    private function file(string $name, string $mime): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'gather');
        $this->temporary[] = $path;

        if (str_starts_with($mime, 'image/')) {
            imagepng(imagecreatetruecolor(32, 32), $path);
        } elseif ($mime === 'application/pdf') {
            file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
        } else {
            file_put_contents($path, '<?php echo "pirate";');
        }

        return new UploadedFile($path, $name, $mime, null, true);
    }

    /**
     * @param list<UploadedFile> $files
     */
    private function createTicket(array $files, int $expected = 201): array
    {
        $this->client->request('POST', '/api/support/tickets', [
            'category' => 'bug_report',
            'subject' => 'Écran figé',
            'message' => 'Voir la capture',
        ], ['attachments' => $files]);
        $response = $this->client->getResponse();
        self::assertSame($expected, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true);
    }

    private function storedPaths(string $ticketId): array
    {
        $this->em->clear();
        $paths = [];
        foreach ($this->em->getRepository(SupportMessage::class)->findBy(['ticket' => \Symfony\Component\Uid\Uuid::fromString($ticketId)]) as $message) {
            array_push($paths, ...array_column($message->getAttachments(), 'path'));
        }

        return array_map(static fn(string $path) => static::getContainer()->getParameter('kernel.project_dir') . '/var/private_media/' . $path, $paths);
    }

    public function testMemberAttachesScreenshotAndPdfAndOnlyTheyCanReadThem(): void
    {
        $clara = $this->user('clara');
        $sam = $this->user('sam');
        $this->loginAs($clara);

        $ticket = $this->createTicket([$this->file('écran figé.png', 'image/png'), $this->file('facture.pdf', 'application/pdf')]);

        $thread = $this->api('GET', '/api/support/tickets/' . $ticket['id']);
        $attachments = $thread['messages'][0]['attachments'];
        self::assertCount(2, $attachments);
        self::assertSame('écran figé.png', $attachments[0]['name']);
        self::assertSame('image/webp', $attachments[0]['mimeType']);
        self::assertSame('application/pdf', $attachments[1]['mimeType']);

        $this->client->request('GET', $attachments[1]['url']);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/pdf', (string) $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));

        $this->loginAs($this->reload($sam));
        $this->client->request('GET', $attachments[1]['url']);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->loginAs(null);
        $this->client->request('GET', $attachments[1]['url']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        array_map('unlink', $this->storedPaths($ticket['id']));
    }

    public function testOtherFileTypesAreRefusedWhateverTheyClaimToBe(): void
    {
        $this->loginAs($this->user('clara'));

        // Un script annoncé comme image : le type est lu dans le contenu réel
        $this->createTicket([$this->file('capture.png', 'text/php')], 422);

        self::assertCount(0, $this->em->getRepository(SupportTicket::class)->findAll());
    }

    public function testATicketAcceptsAtMostFiveFiles(): void
    {
        $this->loginAs($this->user('clara'));

        $files = [];
        for ($i = 0; $i < 6; ++$i) {
            $files[] = $this->file("capture$i.png", 'image/png');
        }

        $this->createTicket($files, 400);
    }

    public function testReplyCanCarryOnlyAFile(): void
    {
        $this->loginAs($this->user('clara'));
        $ticket = $this->api('POST', '/api/support/tickets', ['category' => 'bug_report', 'subject' => 'Sujet', 'message' => 'Message'], 201);

        $this->client->request('POST', '/api/support/tickets/' . $ticket['id'] . '/messages', files: ['attachments' => [$this->file('suite.pdf', 'application/pdf')]]);
        $response = $this->client->getResponse();
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('suite.pdf', json_decode((string) $response->getContent(), true)['attachments'][0]['name']);

        $this->api('POST', '/api/support/tickets/' . $ticket['id'] . '/messages', ['content' => '   '], 400);

        array_map('unlink', $this->storedPaths($ticket['id']));
    }

    public function testInternalNoteAttachmentIsNeverServedToTheMember(): void
    {
        $clara = $this->user('clara');
        $this->loginAs($clara);
        $ticket = $this->createTicket([$this->file('capture.png', 'image/png')]);
        $paths = $this->storedPaths($ticket['id']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entity = $em->find(SupportTicket::class, \Symfony\Component\Uid\Uuid::fromString($ticket['id']));
        $note = new SupportMessage($entity, $em->find(User::class, $clara->getId()), 'Note', true, [['path' => 'x/y.pdf', 'name' => 'note.pdf', 'mimeType' => 'application/pdf', 'size' => 1]]);
        $em->persist($note);
        $em->flush();

        $this->client->request('GET', sprintf('/api/support/tickets/%s/messages/%s/attachments/0', $ticket['id'], $note->getId()));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        array_map('unlink', $paths);
    }

    public function testDeletingTheAccountErasesSupportFiles(): void
    {
        $clara = $this->user('clara');
        $this->loginAs($clara);
        $ticket = $this->createTicket([$this->file('capture.png', 'image/png'), $this->file('facture.pdf', 'application/pdf')]);
        $paths = $this->storedPaths($ticket['id']);
        self::assertCount(2, $paths);
        array_map(static fn(string $path) => self::assertFileExists($path), $paths);

        $this->api('POST', '/api/me/delete-request');
        $this->em->clear();
        $request = $this->em->getRepository(\Fagathe\GatherCore\Entity\UserRequest::class)->findOneBy(['user' => $clara->getId(), 'isUsed' => false]);
        $this->api('DELETE', '/api/me', ['code' => $request->getCode()], 204);

        array_map(static fn(string $path) => self::assertFileDoesNotExist($path), $paths);
    }
}
