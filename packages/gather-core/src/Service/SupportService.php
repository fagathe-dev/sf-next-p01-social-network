<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Doctrine\ORM\EntityManagerInterface;
use Fagathe\CorePhp\Http\ResponseTrait;
use Fagathe\GatherCore\Dto\Input\PublicSupportTicketInput;
use Fagathe\GatherCore\Dto\Input\SupportTicketInput;
use Fagathe\GatherCore\Dto\Mail\SupportReplyMailDto;
use Fagathe\GatherCore\Entity\SupportMessage;
use Fagathe\GatherCore\Entity\SupportTicket;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Social\NotificationType;
use Fagathe\GatherCore\Enum\Support\TicketStatus;
use Fagathe\GatherCore\Generator\AuthLinkGenerator;
use Fagathe\GatherCore\Mailer\MailDispatcher;
use Fagathe\GatherCore\Repository\SupportTicketRepository;
use Fagathe\GatherCore\Security\AudienceResolver;
use Fagathe\GatherCore\Trait\JsonInputTrait;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SupportService
{
    use ResponseTrait, JsonInputTrait;

    private const MAX_REPLY_LENGTH = 10000;
    private const MAX_ATTACHMENTS = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SupportTicketRepository $tickets,
        private readonly AudienceResolver $audiences,
        private readonly MailDispatcher $mailDispatcher,
        private readonly AuthLinkGenerator $links,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly AttachmentStorageService $storage,
        private readonly NotificationService $notifier,
    ) {}

    public function listMine(): object
    {
        $me = $this->audiences->current()->user;

        return $this->sendJson(['items' => array_map(fn(SupportTicket $t) => $this->present($t), $this->tickets->forUser($me))]);
    }

    public function create(Request $request): object
    {
        $me = $this->audiences->current()->user;
        $input = $this->readInput($this->isMultipart($request) ? $request->request->all() : $request->getContent(), SupportTicketInput::class);
        if ($this->isErrorResponse($input, SupportTicketInput::class)) {
            return $input;
        }

        $attachments = $this->storeAttachments($request);
        if (is_object($attachments)) {
            return $attachments;
        }

        $ticket = new SupportTicket($this->newReference(), $me, $input->category, trim($input->subject));
        $ticket->setPageUrl($input->pageUrl ?: null);
        $this->em->persist($ticket);
        $this->em->persist(new SupportMessage($ticket, $me, trim($input->message), attachments: $attachments ?: null));
        $this->em->flush();

        return $this->sendJson($this->present($ticket), Response::HTTP_CREATED);
    }

    /**
     * Demande d'un visiteur. Sans pièce jointe : un dépôt de fichiers ouvert à tous serait trop exposé aux abus.
     */
    public function createPublic(Request $request): object
    {
        $input = $this->readInput($request->getContent(), PublicSupportTicketInput::class);
        if ($this->isErrorResponse($input, PublicSupportTicketInput::class)) {
            return $input;
        }

        $received = ['message' => 'Votre demande a bien été reçue. Nous vous répondrons par e-mail.'];

        // Même réponse pour un robot : il ne doit pas savoir que sa demande a été écartée
        if (($input->website ?? '') !== '') {
            return $this->sendJson($received, Response::HTTP_CREATED);
        }

        $ticket = new SupportTicket($this->newReference(), null, $input->category, trim($input->subject));
        $ticket->setGuest(mb_strtolower(trim($input->email)), trim((string) $input->name) ?: null)->setPageUrl($input->pageUrl ?: null);
        $this->em->persist($ticket);
        $this->em->persist(new SupportMessage($ticket, null, trim($input->message)));
        $this->em->flush();

        return $this->sendJson([...$received, 'reference' => $ticket->getReference()], Response::HTTP_CREATED);
    }

    /** Le fil vu par le membre : les notes internes du staff n'y figurent jamais. */
    public function show(string $id): object
    {
        $ticket = $this->findMine($id);
        if ($ticket === null) {
            return $this->notFound();
        }

        return $this->sendJson([
            'ticket' => $this->present($ticket),
            'messages' => array_map(fn(SupportMessage $m) => $this->presentMessage($m), $this->thread($ticket, includeNotes: false)),
        ]);
    }

    public function reply(string $id, Request $request): object
    {
        $me = $this->audiences->current()->user;
        $ticket = $this->findMine($id);
        if ($ticket === null) {
            return $this->notFound();
        }
        if ($ticket->getStatus() === TicketStatus::Resolved) {
            return $this->sendJson(['message' => 'Ce ticket est résolu : ouvrez-en un nouveau.'], Response::HTTP_CONFLICT);
        }

        $data = $this->isMultipart($request) ? $request->request->all() : json_decode($request->getContent(), true);
        $content = is_array($data) && is_string($data['content'] ?? null) ? trim($data['content']) : '';
        $hasFiles = $request->files->count() > 0;
        if (($content === '' && !$hasFiles) || mb_strlen($content) > self::MAX_REPLY_LENGTH) {
            return $this->sendViolations(['content' => 'Le message est vide ou trop long.']);
        }

        $attachments = $this->storeAttachments($request);
        if (is_object($attachments)) {
            return $attachments;
        }

        $message = new SupportMessage($ticket, $me, $content, attachments: $attachments ?: null);
        $this->em->persist($message);
        if ($ticket->getStatus() === TicketStatus::WaitingUser) {
            $ticket->setStatus(TicketStatus::InProgress);
        }
        $ticket->touch();
        $this->em->flush();

        return $this->sendJson($this->presentMessage($message), Response::HTTP_CREATED);
    }

    /** Réservé au membre propriétaire du ticket : une note interne n'est jamais servie. */
    public function attachment(string $id, string $messageId, int $index): Response
    {
        $ticket = $this->findMine($id);
        $message = $ticket !== null && Uuid::isValid($messageId) ? $this->em->getRepository(SupportMessage::class)->find(Uuid::fromString($messageId)) : null;

        if ($message === null || !$message->getTicket()->getId()->equals($ticket->getId()) || $message->isInternalNote()) {
            return new Response(null, Response::HTTP_NOT_FOUND);
        }

        return $this->attachmentResponse($message, $index, $this->storage->privateAbsolutePath(...));
    }

    /**
     * @param callable(string): string $locate Chemin absolu à partir du chemin enregistré
     */
    public function attachmentResponse(SupportMessage $message, int $index, callable $locate): Response
    {
        $attachment = $message->getAttachments()[$index] ?? null;
        $path = $attachment !== null ? $locate($attachment['path']) : null;
        if ($path === null || !is_file($path)) {
            return new Response(null, Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $attachment['mimeType']);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $attachment['name'], 'piece-jointe.' . pathinfo($path, PATHINFO_EXTENSION));
        $response->setPrivate();

        return $response;
    }

    /** Réponse ou note interne depuis le back-office. Seule une réponse publique prévient le membre. */
    public function staffReply(SupportTicket $ticket, User $staff, string $content, bool $isInternalNote, ?TicketStatus $status): void
    {
        $this->em->persist(new SupportMessage($ticket, $staff, trim($content), $isInternalNote));

        if ($ticket->getAssignedTo() === null) {
            $ticket->setAssignedTo($staff);
        }
        if ($status !== null) {
            $ticket->setStatus($status);
        } elseif (!$isInternalNote) {
            $ticket->setStatus(TicketStatus::WaitingUser);
        }
        $ticket->touch();
        $this->em->flush();

        if (!$isInternalNote && $ticket->getUser() !== null) {
            // Le nom de l'agent n'est pas exposé : la notification vient « du support »
            $this->notifier->notify($ticket->getUser(), null, NotificationType::SupportTicketReply, $ticket);
        }

        if (!$isInternalNote) {
            // Un visiteur n'a pas de fil à consulter : il reçoit la réponse entière
            $this->mailDispatcher->dispatchSilently(new SupportReplyMailDto(
                recipientEmail: $ticket->getRequesterEmail(),
                recipientName: $ticket->getRequesterName(),
                reference: $ticket->getReference(),
                subject: $ticket->getSubject(),
                excerpt: $ticket->isGuest() ? trim($content) : mb_substr(trim($content), 0, 300),
                ticketUrl: $ticket->isGuest() ? null : $this->links->ticketUrl((string) $ticket->getId()),
            ));
        }
    }

    /**
     * @return list<SupportMessage>
     */
    public function thread(SupportTicket $ticket, bool $includeNotes): array
    {
        $criteria = ['ticket' => $ticket];
        if (!$includeNotes) {
            $criteria['isInternalNote'] = false;
        }

        return $this->em->getRepository(SupportMessage::class)->findBy($criteria, ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    private function isMultipart(Request $request): bool
    {
        return $request->request->count() > 0 || $request->files->count() > 0;
    }

    /**
     * @return list<array{path: string, name: string, mimeType: string, size: int}>|object Fichiers enregistrés, ou réponse d'erreur
     */
    private function storeAttachments(Request $request): array|object
    {
        $files = array_values(array_filter((array) $request->files->all('attachments'), static fn($file) => $file instanceof UploadedFile));
        if (count($files) > self::MAX_ATTACHMENTS) {
            return $this->sendViolations(['attachments' => sprintf('%d fichiers au maximum par message.', self::MAX_ATTACHMENTS)]);
        }

        $stored = [];
        try {
            foreach ($files as $file) {
                $stored[] = $this->storage->storeSupportFile($file);
            }
        } catch (AttachmentRejectedException $e) {
            $this->storage->deleteFiles(array_map(fn(array $attachment) => $this->storage->privateAbsolutePath($attachment['path']), $stored));

            return $this->sendJson(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $stored;
    }

    private function newReference(): string
    {
        do {
            $reference = sprintf('#TCK-%s-%s', date('Y'), strtoupper(bin2hex(random_bytes(2))));
        } while ($this->tickets->findOneBy(['reference' => $reference]) !== null);

        return $reference;
    }

    private function findMine(string $id): ?SupportTicket
    {
        $ticket = Uuid::isValid($id) ? $this->tickets->find(Uuid::fromString($id)) : null;
        $me = $this->audiences->current()->user;

        return $ticket !== null && $ticket->getUser()?->getId()->equals($me->getId()) ? $ticket : null;
    }

    /** @return array<string, mixed> */
    private function present(SupportTicket $ticket): array
    {
        return [
            'id' => (string) $ticket->getId(),
            'reference' => $ticket->getReference(),
            'category' => $ticket->getCategory()->value,
            'subject' => $ticket->getSubject(),
            'status' => $ticket->getStatus()->value,
            'priority' => $ticket->getPriority()->value,
            'pageUrl' => $ticket->getPageUrl(),
            'createdAt' => $ticket->getCreatedAt(),
            'updatedAt' => $ticket->getUpdatedAt(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentMessage(SupportMessage $message): array
    {
        $sender = $message->getSender();
        $owner = $message->getTicket()->getUser();
        $isMine = $sender === null || ($owner !== null && $sender->getId()->equals($owner->getId()));

        return [
            'id' => (string) $message->getId(),
            'content' => $message->getContent(),
            // Le nom de l'agent n'a pas à être exposé au membre
            'sender' => $isMine ? 'me' : 'support',
            'attachments' => array_map(
                static fn(int $index, array $attachment) => [
                    'url' => sprintf('/api/support/tickets/%s/messages/%s/attachments/%d', $message->getTicket()->getId(), $message->getId(), $index),
                    'name' => $attachment['name'],
                    'mimeType' => $attachment['mimeType'],
                    'size' => $attachment['size'],
                ],
                array_keys($message->getAttachments()),
                $message->getAttachments(),
            ),
            'createdAt' => $message->getCreatedAt(),
        ];
    }

    private function notFound(): object
    {
        return $this->sendJson(['message' => 'Ticket introuvable.'], Response::HTTP_NOT_FOUND);
    }
}
