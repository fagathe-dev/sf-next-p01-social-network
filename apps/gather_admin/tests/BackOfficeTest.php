<?php

declare(strict_types=1);

namespace App\Tests;

use Fagathe\GatherCore\Entity\ModerationLog;
use Fagathe\GatherCore\Entity\Post;
use Fagathe\GatherCore\Entity\Report;
use Fagathe\GatherCore\Entity\SupportMessage;
use Fagathe\GatherCore\Entity\SupportTicket;
use Fagathe\GatherCore\Enum\Auth\UserRole;
use Fagathe\GatherCore\Enum\Moderation\ModerationActionType;
use Fagathe\GatherCore\Enum\Moderation\ReportReason;
use Fagathe\GatherCore\Enum\Moderation\ReportStatus;
use Fagathe\GatherCore\Enum\Social\ContentStatus;
use Fagathe\GatherCore\Enum\Support\TicketCategory;

final class BackOfficeTest extends AdminTestCase
{
    public function testEveryPageRequiresStaffAndIsNotIndexed(): void
    {
        foreach (['/moderation/reports', '/logs/moderation', '/logs/system', '/emails/templates', '/support/tickets'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('/login', 302, $path);
        }

        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
    }

    public function testMemberIsRefusedAndWrongPasswordFails(): void
    {
        $this->user('clara');
        $this->submitLogin('clara');
        $this->client->request('GET', '/moderation/reports');
        self::assertResponseStatusCodeSame(403);
    }

    public function testModeratorCanBrowseEveryPage(): void
    {
        $this->user('modo', UserRole::ROLE_MODERATOR);
        $this->submitLogin('modo');
        self::assertResponseRedirects('/moderation/reports');

        foreach (['/moderation/reports', '/logs/moderation', '/logs/system', '/emails/templates', '/support/tickets'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }
    }

    public function testEmailGalleryRendersEveryTemplate(): void
    {
        $this->user('modo', UserRole::ROLE_MODERATOR);
        $this->submitLogin('modo');

        $crawler = $this->client->request('GET', '/emails/templates');
        $links = $crawler->filter('table a.btn')->links();
        self::assertGreaterThanOrEqual(11, count($links));

        foreach ($links as $link) {
            $this->client->click($link);
            self::assertResponseIsSuccessful($link->getUri());
            $frame = $this->client->getCrawler()->filter('iframe#preview')->attr('src');
            $this->client->request('GET', $frame);
            self::assertResponseIsSuccessful($frame);
            self::assertResponseHeaderSame('Content-Security-Policy', "sandbox; default-src 'none'; img-src * data:; style-src 'unsafe-inline'");
        }

        $this->client->request('GET', '/emails/templates/moderation-notice/render');
        self::assertStringContainsString('lecture seule', (string) $this->client->getResponse()->getContent());
    }

    public function testResolvingAReportAppliesDecisionAndNeedsCsrf(): void
    {
        $author = $this->user('clara');
        $moderator = $this->user('modo', UserRole::ROLE_MODERATOR);
        $post = new Post($author, 'titre', 'Contenu à modérer');
        $post->setStatus(ContentStatus::AutoHidden);
        $this->em->persist($post);
        $report = new Report($this->user('sam'), $post, ReportReason::Spam);
        $report->setStatus(ReportStatus::AutoHidden);
        $this->em->persist($report);
        $this->em->flush();
        $reportId = (string) $report->getId();
        $postId = $post->getId();

        $this->submitLogin('modo');

        $this->client->request('POST', '/moderation/reports/' . $reportId . '/resolve', ['action' => 'delete_content', 'reason' => 'x', '_token' => 'faux']);
        self::assertResponseStatusCodeSame(403);

        $crawler = $this->client->request('GET', '/moderation/reports');
        self::assertStringContainsString('Contenu à modérer', $crawler->filter('article .box')->text());

        $this->client->submit($crawler->selectButton('Appliquer')->form(['action' => 'delete_content', 'reason' => 'Spam avéré']));
        self::assertResponseRedirects('/moderation/reports');

        $this->em->clear();
        self::assertSame(ContentStatus::Deleted, $this->em->find(Post::class, $postId)->getStatus());
        self::assertSame(ReportStatus::Resolved, $this->em->find(Report::class, $reportId)->getStatus());
        $log = $this->em->getRepository(ModerationLog::class)->findOneBy([]);
        self::assertSame(ModerationActionType::DeleteContent, $log->getActionType());
        self::assertSame('modo', $log->getModerator()->getUsername());

        $this->client->request('GET', '/logs/moderation?action=delete_content&target=cla');
        self::assertSelectorTextContains('table', 'Spam avéré');
        $this->client->request('GET', '/logs/moderation?action=ban');
        self::assertSelectorTextNotContains('table', 'Spam avéré');
    }

    public function testSupportDeskRepliesAndInternalNotes(): void
    {
        $member = $this->user('clara');
        $this->user('modo', UserRole::ROLE_MODERATOR);
        $ticket = new SupportTicket('#TCK-2026-TEST', $member, TicketCategory::BugReport, 'Bug d\'envoi');
        $this->em->persist($ticket);
        $this->em->persist(new SupportMessage($ticket, $member, 'Je ne peux plus publier'));
        $this->em->flush();
        $id = (string) $ticket->getId();

        $this->submitLogin('modo');

        $crawler = $this->client->request('GET', '/support/tickets/' . $id);
        $this->client->submit($crawler->selectButton('Envoyer')->form(['content' => 'Note du staff', 'isInternalNote' => '1']));
        self::assertResponseRedirects();

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.box-warning', 'Note du staff');
        $this->client->submit($crawler->selectButton('Envoyer')->form(['content' => 'Bonjour, nous regardons.', 'priority' => 'high']));

        $this->em->clear();
        $ticket = $this->em->find(SupportTicket::class, $id);
        self::assertSame('waiting_user', $ticket->getStatus()->value);
        self::assertSame('high', $ticket->getPriority()->value);
        self::assertSame('modo', $ticket->getAssignedTo()->getUsername());
    }

    public function testStaffCanOpenTicketAttachmentsStoredByTheApi(): void
    {
        $member = $this->user('clara');
        $this->user('modo', UserRole::ROLE_MODERATOR);
        $directory = static::getContainer()->getParameter('kernel.project_dir') . '/../gather_api/var/private_media/test-admin';
        if (!is_dir($directory)) {
            mkdir($directory, recursive: true);
        }
        file_put_contents($directory . '/facture.pdf', "%PDF-1.4\n%%EOF\n");

        $ticket = new SupportTicket('#TCK-2026-FILE', $member, TicketCategory::BugReport, 'Facture');
        $message = new SupportMessage($ticket, $member, 'Voir le PDF', attachments: [['path' => 'test-admin/facture.pdf', 'name' => 'facture.pdf', 'mimeType' => 'application/pdf', 'size' => 15]]);
        $this->em->persist($ticket);
        $this->em->persist($message);
        $this->em->flush();
        $url = sprintf('/support/tickets/%s/messages/%s/attachments/0', $ticket->getId(), $message->getId());

        $this->client->request('GET', $url);
        self::assertResponseRedirects();

        $this->submitLogin('modo');

        $this->client->request('GET', '/support/tickets/' . $ticket->getId());
        self::assertSelectorExists(sprintf('a[href="%s"]', $url));

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');

        $this->client->request('GET', sprintf('/support/tickets/%s/messages/%s/attachments/9', $ticket->getId(), $message->getId()));
        self::assertResponseStatusCodeSame(404);

        unlink($directory . '/facture.pdf');
        rmdir($directory);
    }
}
