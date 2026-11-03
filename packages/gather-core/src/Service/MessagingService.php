<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Doctrine\ORM\EntityManagerInterface;
use Fagathe\CorePhp\Http\ResponseTrait;
use Fagathe\GatherCore\Dto\Mail\NewMessageMailDto;
use Fagathe\GatherCore\Dto\Output\UserSummaryOutput;
use Fagathe\GatherCore\Entity\Conversation;
use Fagathe\GatherCore\Entity\ConversationParticipant;
use Fagathe\GatherCore\Entity\Message;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Social\FriendshipStatus;
use Fagathe\GatherCore\Generator\AuthLinkGenerator;
use Fagathe\GatherCore\Mailer\MailDispatcher;
use Fagathe\GatherCore\Repository\ConversationRepository;
use Fagathe\GatherCore\Repository\FriendshipRepository;
use Fagathe\GatherCore\Repository\MessageRepository;
use Fagathe\GatherCore\Repository\UserRepository;
use Fagathe\GatherCore\Security\Audience;
use Fagathe\GatherCore\Security\AudienceResolver;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Uid\Uuid;

final class MessagingService
{
    use ResponseTrait;

    private const MAX_PARTICIPANTS = 20;
    private const MAX_LENGTH = 10000;
    private const MIN_SEARCH_LENGTH = 2;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationRepository $conversations,
        private readonly MessageRepository $messages,
        private readonly UserRepository $users,
        private readonly FriendshipRepository $friendships,
        private readonly AudienceResolver $audiences,
        private readonly AttachmentStorageService $storage,
        private readonly MailDispatcher $mailDispatcher,
        private readonly AuthLinkGenerator $links,
    ) {}

    public function list(): object
    {
        $me = $this->audiences->current()->user;

        return $this->sendJson([
            'items' => array_map(fn(Conversation $c) => $this->present($c, $me), $this->conversations->forUser($me)),
            'unreadTotal' => $this->conversations->unreadTotal($me),
        ]);
    }

    /** Ouvre une conversation : réutilise la conversation privée existante entre deux personnes. */
    public function open(Request $request): object
    {
        $audience = $this->audiences->current();
        if (!$audience->canWrite()) {
            return $this->sendJson(['message' => 'Votre compte ne peut pas envoyer de message pour le moment.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $usernames = is_array($data) && is_array($data['usernames'] ?? null) ? array_values(array_unique(array_filter($data['usernames'], 'is_string'))) : [];
        $title = is_array($data) && is_string($data['title'] ?? null) ? mb_substr(trim($data['title']), 0, 120) : '';

        if ($usernames === [] || count($usernames) > self::MAX_PARTICIPANTS) {
            return $this->sendViolations(['usernames' => sprintf('Choisissez de 1 à %d participants.', self::MAX_PARTICIPANTS)]);
        }

        $others = [];
        foreach ($usernames as $username) {
            $user = $this->users->findOneBy(['username' => $username]);
            if ($user === null || $audience->isSelf($user) || !$this->canContact($audience, $user)) {
                return $this->sendViolations(['usernames' => sprintf('Impossible d\'écrire à @%s.', $username)]);
            }
            $others[] = $user;
        }

        if (count($others) === 1 && $title === '') {
            $existing = $this->conversations->findDirectBetween($audience->user, $others[0]);
            if ($existing !== null) {
                return $this->sendJson($this->present($existing, $audience->user));
            }
        }

        $conversation = new Conversation($title !== '' ? $title : (count($others) > 1 ? 'Groupe' : null));
        $conversation->addParticipant($audience->user);
        foreach ($others as $other) {
            $conversation->addParticipant($other);
        }
        $this->em->persist($conversation);
        $this->em->flush();

        return $this->sendJson($this->present($conversation, $audience->user), Response::HTTP_CREATED);
    }

    /** L'ouverture du fil marque la conversation comme lue. */
    public function history(string $id, int $page): object
    {
        $me = $this->audiences->current()->user;
        $conversation = $this->findOwned($id, $me);
        if ($conversation === null) {
            return $this->notFound();
        }

        $conversation->participantFor($me)->markRead();
        $this->em->flush();

        $result = $this->messages->listFor($conversation, $page);

        return $this->sendJson([
            'conversation' => $this->present($conversation, $me),
            'items' => array_map(fn(Message $m) => $this->presentMessage($m), $result['items']),
            'page' => $result['page'],
            'hasMore' => $result['hasMore'],
        ]);
    }

    public function send(string $id, Request $request): object
    {
        $audience = $this->audiences->current();
        $conversation = $this->findOwned($id, $audience->user);
        if ($conversation === null) {
            return $this->notFound();
        }
        if (!$audience->canWrite()) {
            return $this->sendJson(['message' => 'Votre compte ne peut pas envoyer de message pour le moment.'], Response::HTTP_FORBIDDEN);
        }

        $isMultipart = $request->request->count() > 0 || $request->files->count() > 0;
        $data = $isMultipart ? $request->request->all() : (json_decode($request->getContent(), true) ?: []);
        $content = trim((string) ($data['content'] ?? ''));
        $file = $request->files->get('attachment');

        if (mb_strlen($content) > self::MAX_LENGTH) {
            return $this->sendViolations(['content' => 'Message trop long.']);
        }
        if ($content === '' && !$file instanceof UploadedFile) {
            return $this->sendViolations(['content' => 'Le message est vide.']);
        }

        $stored = null;
        if ($file instanceof UploadedFile) {
            try {
                $stored = $this->storage->storeMessageFile($file);
            } catch (AttachmentRejectedException $e) {
                return $this->sendViolations(['attachment' => $e->getMessage()]);
            }
        }

        $message = new Message($conversation, $audience->user, $content, $stored['path'] ?? null, $stored['name'] ?? null, $stored['mimeType'] ?? null);
        $this->em->persist($message);

        foreach ($conversation->getParticipants() as $participant) {
            if ($audience->isSelf($participant->getUser())) {
                $participant->markRead();
                continue;
            }

            // Un seul e-mail tant que le destinataire n'a pas lu : pas un par message
            if ($participant->getUnreadCount() === 0) {
                $this->notify($participant, $audience->user, $conversation, $content);
            }
            $participant->incrementUnread();
        }
        $conversation->touch();
        $this->em->flush();

        return $this->sendJson($this->presentMessage($message), Response::HTTP_CREATED);
    }

    public function attachment(string $conversationId, string $messageId): Response
    {
        $me = $this->audiences->current()->user;
        $conversation = $this->findOwned($conversationId, $me);
        $message = $conversation !== null && Uuid::isValid($messageId) ? $this->messages->find(Uuid::fromString($messageId)) : null;

        if ($message === null || !$message->getConversation()->getId()->equals($conversation->getId()) || $message->getAttachmentPath() === null) {
            return new Response(null, Response::HTTP_NOT_FOUND);
        }

        $path = $this->storage->privateAbsolutePath($message->getAttachmentPath());
        if (!is_file($path)) {
            return new Response(null, Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($path);
        if ($message->getAttachmentMimeType() !== null) {
            $response->headers->set('Content-Type', $message->getAttachmentMimeType());
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $message->getAttachmentName() ?? basename($path), 'piece-jointe.' . pathinfo($path, PATHINFO_EXTENSION));
        $response->setPrivate();

        return $response;
    }

    /** Fichiers échangés dans une conversation, pour le panneau de détails. */
    public function attachments(string $id): object
    {
        $conversation = $this->findOwned($id, $this->audiences->current()->user);
        if ($conversation === null) {
            return $this->notFound();
        }

        return $this->sendJson(['items' => array_map(fn(Message $m) => $this->presentMessage($m), $this->messages->attachmentsOf($conversation))]);
    }

    public function search(string $id, string $query): object
    {
        $conversation = $this->findOwned($id, $this->audiences->current()->user);
        if ($conversation === null) {
            return $this->notFound();
        }

        $query = trim($query);
        if (mb_strlen($query) < self::MIN_SEARCH_LENGTH) {
            return $this->sendJson(['items' => []]);
        }

        return $this->sendJson(['items' => array_map(fn(Message $m) => $this->presentMessage($m), $this->messages->searchIn($conversation, mb_substr($query, 0, 120)))]);
    }

    public function unread(): int
    {
        return $this->conversations->unreadTotal($this->audiences->current()->user);
    }

    private function canContact(Audience $audience, User $other): bool
    {
        if (!$other->isVisible()) {
            return false;
        }

        $friendship = $this->friendships->findBetween($audience->user, $other);
        if ($friendship?->getStatus() === FriendshipStatus::Blocked) {
            return false;
        }

        return !$other->isPrivate() || $friendship?->getStatus() === FriendshipStatus::Accepted;
    }

    private function notify(ConversationParticipant $participant, User $sender, Conversation $conversation, string $content): void
    {
        $recipient = $participant->getUser();
        if (($recipient->getSettings()['email_notifications'] ?? true) !== true) {
            return;
        }

        $this->mailDispatcher->dispatchSilently(new NewMessageMailDto(
            recipientEmail: (string) $recipient->getEmail(),
            recipientName: (string) $recipient->getDisplayName(),
            senderName: (string) $sender->getDisplayName(),
            excerpt: mb_substr($content, 0, 140),
            conversationUrl: $this->links->conversationUrl((string) $conversation->getId()),
        ));
    }

    private function findOwned(string $id, User $user): ?Conversation
    {
        $conversation = Uuid::isValid($id) ? $this->conversations->find(Uuid::fromString($id)) : null;

        return $conversation?->participantFor($user) !== null ? $conversation : null;
    }

    /** @return array<string, mixed> */
    private function present(Conversation $conversation, User $me): array
    {
        $last = $this->messages->latestOf($conversation);
        $mine = $conversation->participantFor($me);

        $participants = [];
        $readBy = [];
        foreach ($conversation->getParticipants() as $participant) {
            if ($participant->getUser()->getId()->equals($me->getId())) {
                continue;
            }
            $participants[] = UserSummaryOutput::from($participant->getUser());
            $readBy[] = ['username' => $participant->getUser()->getUsername(), 'readAt' => $participant->getLastReadAt()];
        }

        return [
            'id' => (string) $conversation->getId(),
            'title' => $conversation->getTitle(),
            'participants' => $participants,
            'readBy' => $readBy,
            'unreadCount' => $mine?->getUnreadCount() ?? 0,
            'updatedAt' => $conversation->getUpdatedAt(),
            'lastMessage' => $last !== null ? $this->presentMessage($last) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function presentMessage(Message $message): array
    {
        return [
            'id' => (string) $message->getId(),
            'content' => $message->getContent(),
            'sender' => UserSummaryOutput::from($message->getSender()),
            'attachment' => $this->presentAttachment($message),
            'createdAt' => $message->getCreatedAt(),
        ];
    }

    /** @return array{url: string, name: string, mimeType: string, size: int}|null */
    private function presentAttachment(Message $message): ?array
    {
        if ($message->getAttachmentPath() === null) {
            return null;
        }

        return [
            'url' => sprintf('/api/conversations/%s/messages/%s/attachment', $message->getConversation()->getId(), $message->getId()),
            'name' => $message->getAttachmentName() ?? 'Pièce jointe',
            'mimeType' => $message->getAttachmentMimeType() ?? 'application/octet-stream',
            'size' => (int) @filesize($this->storage->privateAbsolutePath($message->getAttachmentPath())),
        ];
    }

    private function notFound(): object
    {
        return $this->sendJson(['message' => 'Conversation introuvable.'], Response::HTTP_NOT_FOUND);
    }
}
