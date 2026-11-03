<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Doctrine\ORM\EntityManagerInterface;
use Fagathe\CorePhp\Http\ResponseTrait;
use Fagathe\GatherCore\Dto\Output\UserSummaryOutput;
use Fagathe\GatherCore\Entity\Comment;
use Fagathe\GatherCore\Entity\Notification;
use Fagathe\GatherCore\Entity\Post;
use Fagathe\GatherCore\Entity\SocialGroup;
use Fagathe\GatherCore\Entity\SupportTicket;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Social\NotificationType;
use Fagathe\GatherCore\Repository\NotificationRepository;
use Fagathe\GatherCore\Security\AudienceResolver;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Création et lecture des notifications. Le texte et le lien sont calculés à la lecture : une cible supprimée
 * ou devenue invisible pour le destinataire ne donne plus de lien.
 */
final class NotificationService
{
    use ResponseTrait;

    public const PREVIEW_SIZE = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationRepository $notifications,
        private readonly AudienceResolver $audiences,
    ) {}

    /** Sans effet pour soi-même ou pour un compte invisible ; regroupe avec une notification semblable non lue. */
    public function notify(User $recipient, ?User $actor, NotificationType $type, Post|Comment|SocialGroup|SupportTicket|User $target): void
    {
        if (!$recipient->isVisible() || ($actor !== null && $actor->getId()->equals($recipient->getId()))) {
            return;
        }

        $targetType = match (true) {
            $target instanceof Post => 'post',
            $target instanceof Comment => 'comment',
            $target instanceof SocialGroup => 'group',
            $target instanceof SupportTicket => 'ticket',
            default => 'user',
        };
        $targetId = (string) $target->getId();

        $existing = $this->notifications->findUnreadFor($recipient, $type, $targetType, $targetId);
        if ($existing !== null) {
            $existing->addActor($actor);
        } else {
            $this->em->persist(new Notification($recipient, $actor, $type, $targetType, $targetId));
        }
        $this->em->flush();
    }

    public function list(string $filter, int $page): object
    {
        $me = $this->audiences->current()->user;
        $result = $this->notifications->listFor($me, $filter === 'unread', $page);

        return $this->sendJson([
            'items' => array_map(fn(Notification $n) => $this->present($n), $result['items']),
            'page' => $result['page'],
            'hasMore' => $result['hasMore'],
            'unreadCount' => $this->notifications->countFor($me, true),
        ]);
    }

    /** Aperçu de la cloche : les dernières notifications et le total, pour savoir s'il faut « Voir plus ». */
    public function preview(): object
    {
        $me = $this->audiences->current()->user;
        $result = $this->notifications->listFor($me, false, 1, self::PREVIEW_SIZE);

        return $this->sendJson([
            'items' => array_map(fn(Notification $n) => $this->present($n), $result['items']),
            'unreadCount' => $this->notifications->countFor($me, true),
            'total' => $this->notifications->countFor($me, false),
        ]);
    }

    public function markRead(string $id): object
    {
        $me = $this->audiences->current()->user;
        $notification = Uuid::isValid($id) ? $this->notifications->find(Uuid::fromString($id)) : null;
        if ($notification === null || !$notification->getRecipient()->getId()->equals($me->getId())) {
            return $this->sendJson(['message' => 'Notification introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $notification->markRead();
        $this->em->flush();

        return $this->sendJson($this->present($notification));
    }

    public function markAllRead(): object
    {
        $this->notifications->markAllRead($this->audiences->current()->user);

        return $this->sendJson(null, Response::HTTP_NO_CONTENT);
    }

    public function unreadCount(User $user): int
    {
        return $this->notifications->countFor($user, true);
    }

    /** @return array<string, mixed> */
    private function present(Notification $notification): array
    {
        $actor = $notification->getLastActor();
        $target = $this->resolveTarget($notification);

        return [
            'id' => (string) $notification->getId(),
            'type' => $notification->getType()->value,
            'message' => $this->message($notification, $target['label']),
            'href' => $target['href'],
            'actor' => $actor !== null ? UserSummaryOutput::from($actor) : null,
            'actorsCount' => $notification->getActorsCount(),
            'isRead' => $notification->isRead(),
            'createdAt' => $notification->getCreatedAt(),
            'updatedAt' => $notification->getUpdatedAt(),
        ];
    }

    private function message(Notification $notification, ?string $label): string
    {
        $count = $notification->getActorsCount();
        $name = $notification->getLastActor()?->getDisplayName() ?? 'Un membre';
        $who = $count > 1 ? sprintf('%s et %d autre%s', $name, $count - 1, $count > 2 ? 's' : '') : $name;
        $verb = static fn(string $one, string $many) => $count > 1 ? $many : $one;

        return match ($notification->getType()) {
            NotificationType::FriendRequestReceived => sprintf('%s %s une demande d\'ami', $who, $verb('vous a envoyé', 'vous ont envoyé')),
            NotificationType::FriendRequestAccepted => sprintf('%s %s votre demande d\'ami', $who, $verb('a accepté', 'ont accepté')),
            NotificationType::PostCommented => sprintf('%s %s votre publication', $who, $verb('a commenté', 'ont commenté')),
            NotificationType::CommentReplied => sprintf('%s %s à votre commentaire', $who, $verb('a répondu', 'ont répondu')),
            NotificationType::CommentLiked => sprintf('%s %s votre commentaire', $who, $verb('a aimé', 'ont aimé')),
            NotificationType::PostLiked => sprintf('%s %s votre publication', $who, $verb('a aimé', 'ont aimé')),
            NotificationType::PostReposted => sprintf('%s %s votre publication', $who, $verb('a republié', 'ont republié')),
            NotificationType::UserMentioned => sprintf('%s %s', $who, $verb('vous a mentionné', 'vous ont mentionné')),
            NotificationType::GroupInvitation => sprintf('%s vous invite à rejoindre « %s »', $name, $label ?? 'une communauté'),
            NotificationType::GroupJoinApproved => sprintf('Votre demande pour rejoindre « %s » a été acceptée', $label ?? 'une communauté'),
            NotificationType::SupportTicketReply => sprintf('Le support a répondu à votre demande %s', $label ?? ''),
        };
    }

    /**
     * @return array{href: ?string, label: ?string}
     */
    private function resolveTarget(Notification $notification): array
    {
        $id = Uuid::isValid($notification->getTargetId()) ? Uuid::fromString($notification->getTargetId()) : null;
        if ($id === null) {
            return ['href' => null, 'label' => null];
        }

        $audience = $this->audiences->for($notification->getRecipient());
        $postPath = static fn(Post $post) => sprintf('/post/%s-%s', $post->getSlug(), $post->getId());

        switch ($notification->getTargetType()) {
            case 'post':
                $post = $this->em->find(Post::class, $id);

                return ['href' => $post !== null && $audience->canViewPost($post) ? $postPath($post) : null, 'label' => null];
            case 'comment':
                $comment = $this->em->find(Comment::class, $id);
                $visible = $comment !== null && $comment->isPublished() && $audience->canViewPost($comment->getPost());

                return ['href' => $visible ? $postPath($comment->getPost()) . '#comment-' . $comment->getId() : null, 'label' => null];
            case 'group':
                $group = $this->em->find(SocialGroup::class, $id);

                return ['href' => $group !== null ? '/g/' . $group->getSlug() : null, 'label' => $group?->getName()];
            case 'ticket':
                $ticket = $this->em->find(SupportTicket::class, $id);

                return ['href' => $ticket !== null ? '/support/' . $ticket->getId() : null, 'label' => $ticket?->getReference()];
            default:
                $user = $this->em->find(User::class, $id);

                return ['href' => $user !== null && $user->isVisible() ? '/u/' . $user->getUsername() : null, 'label' => null];
        }
    }
}
