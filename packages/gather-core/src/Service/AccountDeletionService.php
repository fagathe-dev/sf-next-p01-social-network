<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Fagathe\GatherCore\Entity\GroupMember;
use Fagathe\GatherCore\Entity\PostAttachment;
use Fagathe\GatherCore\Entity\SocialGroup;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Social\GroupMemberStatus;
use Fagathe\GatherCore\Enum\Social\GroupRole;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Efface un compte et tout ce qui lui appartient :
 *  - publications, commentaires, réactions, messages envoyés, tickets de support et fichiers associés ;
 *  - une conversation sans au moins deux participants restants disparaît avec ses messages ;
 *  - une communauté passe à son modérateur (sinon son plus ancien membre) ; sans autre membre, elle disparaît.
 *
 * Le journal de modération reste, sans lien vers le compte (ON DELETE SET NULL). Les fichiers ne sont
 * effacés qu'après la validation de la transaction : un échec ne doit laisser aucune donnée sans son fichier.
 */
final class AccountDeletionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttachmentStorageService $storage,
    ) {}

    public function purge(User $user): void
    {
        /** @var list<string> $files */
        $files = $this->em->wrapInTransaction(function () use ($user): array {
            $connection = $this->em->getConnection();
            $id = $user->getId()->toBinary();

            $orphanGroups = $this->releaseOwnedGroups($user);
            $orphanGroupIds = array_map(static fn(SocialGroup $group): string => $group->getId()->toBinary(), $orphanGroups);

            $conversationIds = $connection->fetchFirstColumn(
                'SELECT p.conversation_id FROM conversation_participant p
                 WHERE p.user_id = :id AND (SELECT COUNT(*) FROM conversation_participant o WHERE o.conversation_id = p.conversation_id AND o.user_id <> :id) <= 1',
                ['id' => $id]
            );

            $files = $this->collectFiles($user, $orphanGroups, $conversationIds);

            // Relevés avant les suppressions : les compteurs des contenus d'autrui sont recalculés à la fin
            $touchedPostIds = $connection->fetchFirstColumn(
                'SELECT post_id FROM comment WHERE author_id = :id
                 UNION SELECT post_id FROM post_like WHERE user_id = :id
                 UNION SELECT post_id FROM bookmark WHERE user_id = :id',
                ['id' => $id]
            );
            $joinedGroupIds = $connection->fetchFirstColumn('SELECT group_id FROM group_member WHERE user_id = :id', ['id' => $id]);
            $repostedIds = $connection->fetchFirstColumn('SELECT original_post_id FROM post WHERE author_id = :id AND original_post_id IS NOT NULL', ['id' => $id]);
            $likedCommentIds = $connection->fetchFirstColumn('SELECT comment_id FROM comment_like WHERE user_id = :id', ['id' => $id]);

            $binaryIds = ['ids' => ArrayParameterType::BINARY];
            $connection->executeStatement('DELETE FROM conversation WHERE id IN (:ids)', ['ids' => $conversationIds], $binaryIds);
            $connection->executeStatement('DELETE FROM social_group WHERE id IN (:ids)', ['ids' => $orphanGroupIds], $binaryIds);
            $connection->executeStatement('DELETE FROM message WHERE sender_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM support_ticket WHERE user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM support_message WHERE sender_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM post WHERE author_id = :id', ['id' => $id]);

            $this->em->remove($user);
            $this->em->flush();

            $connection->executeStatement(
                "UPDATE post p SET
                    likes_count = (SELECT COUNT(*) FROM post_like l WHERE l.post_id = p.id),
                    comments_count = (SELECT COUNT(*) FROM comment c WHERE c.post_id = p.id AND c.status = 'published'),
                    bookmarks_count = (SELECT COUNT(*) FROM bookmark b WHERE b.post_id = p.id)
                 WHERE p.id IN (:ids)",
                ['ids' => $touchedPostIds],
                $binaryIds
            );
            foreach ($repostedIds as $originalId) {
                $count = (int) $connection->fetchOne("SELECT COUNT(*) FROM post WHERE original_post_id = :post AND status = 'published'", ['post' => $originalId]);
                $connection->executeStatement('UPDATE post SET reposts_count = :count WHERE id = :post', ['count' => $count, 'post' => $originalId]);
            }
            $connection->executeStatement(
                'UPDATE comment c SET likes_count = (SELECT COUNT(*) FROM comment_like l WHERE l.comment_id = c.id) WHERE c.id IN (:ids)',
                ['ids' => $likedCommentIds],
                $binaryIds
            );
            $connection->executeStatement(
                'UPDATE social_group g SET members_count = (SELECT COUNT(*) FROM group_member m WHERE m.group_id = g.id AND m.status = :joined) WHERE g.id IN (:ids)',
                ['joined' => GroupMemberStatus::Joined->value, 'ids' => $joinedGroupIds],
                $binaryIds
            );

            return $files;
        });

        $this->em->clear();
        $this->storage->deleteFiles($files);
    }

    /**
     * @return list<SocialGroup> Communautés sans autre membre, à supprimer
     */
    private function releaseOwnedGroups(User $user): array
    {
        $orphans = [];

        foreach ($this->em->getRepository(SocialGroup::class)->findBy(['owner' => $user]) as $group) {
            // « moderator » se trie après « member » : l'ordre décroissant le place en premier
            /** @var GroupMember|null $successor */
            $successor = $this->em->createQueryBuilder()
                ->select('m')
                ->from(GroupMember::class, 'm')
                ->where('m.group = :group AND m.user != :user AND m.status = :joined')
                ->setParameter('group', $group->getId(), UuidType::NAME)
                ->setParameter('user', $user->getId(), UuidType::NAME)
                ->setParameter('joined', GroupMemberStatus::Joined)
                ->orderBy('m.role', 'DESC')
                ->addOrderBy('m.joinedAt', 'ASC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($successor === null) {
                $orphans[] = $group;
                continue;
            }

            $successor->setRole(GroupRole::Owner);
            $group->setOwner($successor->getUser());
        }

        $this->em->flush();

        return $orphans;
    }

    /**
     * @param list<SocialGroup> $orphanGroups
     * @param list<string>      $conversationIds Identifiants binaires
     *
     * @return list<string> Chemins absolus
     */
    private function collectFiles(User $user, array $orphanGroups, array $conversationIds): array
    {
        $files = array_values(array_filter([
            $this->storage->publicImageFile($user->getAvatar()),
            $this->storage->publicImageFile($user->getBanner()),
        ]));

        $attachments = $this->em->createQueryBuilder()
            ->select('a')
            ->from(PostAttachment::class, 'a')
            ->join('a.post', 'p');

        foreach ($orphanGroups as $index => $group) {
            $attachments->orWhere('p.group = :group' . $index)->setParameter('group' . $index, $group->getId(), UuidType::NAME);
        }
        $attachments->orWhere('p.author = :user')->setParameter('user', $user->getId(), UuidType::NAME);

        foreach ($attachments->getQuery()->getResult() as $attachment) {
            array_push($files, ...$this->storage->attachmentFiles($attachment));
        }

        $connection = $this->em->getConnection();
        $id = $user->getId()->toBinary();
        $privatePaths = $connection->fetchFirstColumn(
            'SELECT attachment_path FROM message WHERE attachment_path IS NOT NULL AND (sender_id = :id OR conversation_id IN (:conversations))',
            ['id' => $id, 'conversations' => $conversationIds],
            ['conversations' => ArrayParameterType::BINARY]
        );

        $supportAttachments = $connection->fetchFirstColumn(
            'SELECT m.attachments FROM support_message m JOIN support_ticket t ON t.id = m.ticket_id WHERE m.attachments IS NOT NULL AND t.user_id = :id',
            ['id' => $id]
        );
        foreach ($supportAttachments as $attachments) {
            array_push($privatePaths, ...array_column((array) json_decode($attachments, true), 'path'));
        }

        foreach ($privatePaths as $path) {
            $files[] = $this->storage->privateAbsolutePath($path);
        }

        return $files;
    }
}
