<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Doctrine\ORM\EntityManagerInterface;
use Fagathe\CorePhp\Http\ResponseTrait;
use Fagathe\GatherCore\Dto\Input\CommentInput;
use Fagathe\GatherCore\Dto\Input\CreatePostInput;
use Fagathe\GatherCore\Dto\Input\UpdatePostInput;
use Fagathe\GatherCore\Dto\Output\CommentOutput;
use Fagathe\GatherCore\Entity\Bookmark;
use Fagathe\GatherCore\Entity\Comment;
use Fagathe\GatherCore\Entity\LinkPreview;
use Fagathe\GatherCore\Entity\Post;
use Fagathe\GatherCore\Entity\PostAttachment;
use Fagathe\GatherCore\Entity\Tag;
use Fagathe\GatherCore\Enum\Social\ContentStatus;
use Fagathe\GatherCore\Enum\Social\NotificationType;
use Fagathe\GatherCore\Enum\Social\PostVisibility;
use Fagathe\GatherCore\Repository\BookmarkRepository;
use Fagathe\GatherCore\Repository\CommentRepository;
use Fagathe\GatherCore\Repository\GroupMemberRepository;
use Fagathe\GatherCore\Repository\PostRepository;
use Fagathe\GatherCore\Repository\SocialGroupRepository;
use Fagathe\GatherCore\Repository\TagRepository;
use Fagathe\GatherCore\Repository\UserRepository;
use Fagathe\GatherCore\Security\Audience;
use Fagathe\GatherCore\Security\AudienceResolver;
use Fagathe\GatherCore\Trait\JsonInputTrait;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PostService
{
    use ResponseTrait, JsonInputTrait;

    public const MAX_ATTACHMENTS = 10;
    private const ANONYMOUS_VIEW_TTL = 86400;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PostRepository $posts,
        private readonly CommentRepository $comments,
        private readonly BookmarkRepository $bookmarks,
        private readonly TagRepository $tags,
        private readonly SocialGroupRepository $groups,
        private readonly GroupMemberRepository $members,
        private readonly AudienceResolver $audiences,
        private readonly PostPresenter $presenter,
        private readonly AttachmentStorageService $storage,
        private readonly ContentParser $parser,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly CacheItemPoolInterface $cache,
        private readonly NotificationService $notifier,
        private readonly UserRepository $users,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Lecture
    // ─────────────────────────────────────────────────────────────────────────

    public function feed(string $feed, int $page): object
    {
        $audience = $this->audiences->current();

        $result = match ($feed) {
            'friends' => $this->posts->friendsNetwork($audience, $page),
            'popular' => $this->posts->popular($audience, $page),
            default => $this->posts->recent($audience, $page),
        };

        return $this->sendJson($this->page($result, $audience));
    }

    /** Détail d'un post à partir de « {slug}-{uuid} ». La lecture n'incrémente pas les vues : voir registerView(). */
    public function show(string $slugAndId): object
    {
        $id = self::uuidFromPath($slugAndId);
        $post = $id !== null ? $this->posts->find($id) : null;
        $audience = $this->audiences->current();

        if ($post === null || !$audience->canViewPost($post)) {
            return $this->notFound();
        }

        return $this->sendJson($this->presenter->card($post, $audience));
    }

    /**
     * +1 sur intention de lecture réelle (page dédiée, « Lire la suite », commentaires, visionneuse).
     * Connecté : une vue par utilisateur, garantie par UNIQUE(post_id, user_id).
     * Visiteur : une vue par couple IP / navigateur sur 24 h, via le cache.
     */
    public function registerView(string $id, Request $request): object
    {
        $audience = $this->audiences->current();
        $post = $this->findVisible($id, $audience);
        if ($post === null) {
            return $this->notFound();
        }

        $isNew = $audience->user !== null
            ? $this->insertIgnore('post_view', $post, $audience, 'viewed_at')
            : $this->isNewAnonymousView($post, $request);

        if ($isNew) {
            $this->posts->adjustCounter($post, 'views', 1);
        } elseif ($audience->user !== null) {
            // Déjà comptée : seule la date bouge, pour l'historique « consultés récemment »
            $this->em->getConnection()->executeStatement(
                'UPDATE post_view SET viewed_at = :now WHERE post_id = :post AND user_id = :user',
                ['now' => date('Y-m-d H:i:s'), 'post' => $post->getId()->toBinary(), 'user' => $audience->user->getId()->toBinary()]
            );
        }

        return $this->sendJson(['viewsCount' => $post->getViewsCount(), 'counted' => $isNew]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Création
    // ─────────────────────────────────────────────────────────────────────────

    public function create(Request $request): object
    {
        $audience = $this->audiences->current();
        if (!$audience->canWrite()) {
            return $this->sendJson(['message' => 'Votre compte ne peut pas publier pour le moment.'], Response::HTTP_FORBIDDEN);
        }

        $isMultipart = $request->request->count() > 0 || $request->files->count() > 0;
        $input = $this->readInput($isMultipart ? $request->request->all() : $request->getContent(), CreatePostInput::class);
        if ($this->isErrorResponse($input, CreatePostInput::class)) {
            return $input;
        }

        /** @var list<UploadedFile> $files */
        $files = array_values(array_filter((array) ($request->files->all('attachments') ?: []), static fn($f) => $f instanceof UploadedFile));
        if (count($files) > self::MAX_ATTACHMENTS) {
            return $this->sendViolations(['attachments' => sprintf('%d fichiers maximum par publication.', self::MAX_ATTACHMENTS)]);
        }
        if (trim($input->content) === '' && $files === []) {
            return $this->sendViolations(['content' => 'La publication est vide.']);
        }

        $group = null;
        if ($input->groupSlug !== null && $input->groupSlug !== '') {
            $group = $this->groups->findOneBy(['slug' => $input->groupSlug]);
            if ($group === null || !$audience->isMemberOf($group)) {
                return $this->sendJson(['message' => 'Seuls les membres de la communauté peuvent y publier.'], Response::HTTP_FORBIDDEN);
            }
        } elseif ($input->visibility === PostVisibility::Group) {
            return $this->sendViolations(['groupSlug' => 'Choisissez la communauté cible.']);
        }

        $post = new Post(
            $audience->user,
            $this->parser->slug($input->title ?: $input->content),
            trim($input->content),
            $group !== null ? PostVisibility::Group : $input->visibility,
        );
        $post->setTitle($input->title ?: null)
            ->setGroup($group)
            ->setLocationName($input->locationName ?: null);

        if ($input->linkPreviewId !== null && $input->linkPreviewId !== '') {
            $post->setLinkPreview($this->em->find(LinkPreview::class, Uuid::fromString($input->linkPreviewId)));
        }

        foreach ($this->parser->hashtags($post->getContent()) as $name) {
            $post->addTag($this->findOrCreateTag($name));
        }

        try {
            foreach ($files as $position => $file) {
                $post->addAttachment($this->storage->store($file, $post, $position));
            }
        } catch (AttachmentRejectedException $e) {
            return $this->sendViolations(['attachments' => $e->getMessage()]);
        }

        $this->em->persist($post);
        $this->em->flush();
        $this->notifyMentions($post->getContent(), $post, $audience, $post);

        return $this->sendJson($this->presenter->card($post, $audience), Response::HTTP_CREATED);
    }

    /**
     * Modification par l'auteur seul : le staff modère, il ne réécrit pas. L'état complet est renvoyé
     * (texte, titre, lieu, aperçu de lien, fichiers retirés) ; l'audience reste celle d'origine si elle est absente.
     */
    public function update(string $id, Request $request): object
    {
        $audience = $this->audiences->current();
        $post = $this->findVisible($id, $audience);
        if ($post === null) {
            return $this->notFound();
        }
        if (!$audience->isSelf($post->getAuthor()) || !$post->isPublished()) {
            return $this->forbidden();
        }
        if (!$audience->canWrite()) {
            return $this->sendJson(['message' => 'Votre compte ne peut pas modifier de publication pour le moment.'], Response::HTTP_FORBIDDEN);
        }

        $input = $this->readInput($request->getContent(), UpdatePostInput::class);
        if ($this->isErrorResponse($input, UpdatePostInput::class)) {
            return $input;
        }

        $removed = array_values(array_filter(
            $post->getAttachments()->toArray(),
            static fn(PostAttachment $a) => in_array((string) $a->getId(), $input->removeAttachmentIds, true)
        ));
        $content = trim($input->content);
        if ($content === '' && $post->getAttachments()->count() === count($removed) && !$post->isRepost()) {
            return $this->sendViolations(['content' => 'La publication est vide.']);
        }

        $canChangeAudience = $input->visibility !== null && $post->getGroup() === null && !$post->isRepost();
        if ($canChangeAudience && $input->visibility === PostVisibility::Group) {
            return $this->sendViolations(['visibility' => 'Une publication ne peut pas être déplacée dans une communauté.']);
        }

        // Chemins relevés avant tout changement d'audience : ils dépendent de la racine publique ou privée
        $filesToDelete = [];
        foreach ($removed as $attachment) {
            array_push($filesToDelete, ...$this->storage->attachmentFiles($attachment));
        }

        $wasPublicMedia = $post->hasPublicMedia();
        $previousMentions = $this->parser->mentions($post->getContent());

        if ($canChangeAudience) {
            $post->setVisibility($input->visibility);
        }
        $post->setContent($content)
            ->setTitle($post->isRepost() ? null : ($input->title ?: null))
            ->setLocationName($input->locationName ?: null)
            ->setLinkPreview($input->linkPreviewId ? $this->em->find(LinkPreview::class, Uuid::fromString($input->linkPreviewId)) : null);

        $post->clearTags();
        foreach ($this->parser->hashtags($content) as $name) {
            $post->addTag($this->findOrCreateTag($name));
        }

        foreach ($removed as $attachment) {
            $post->removeAttachment($attachment);
            $this->em->remove($attachment);
        }
        if ($wasPublicMedia !== $post->hasPublicMedia()) {
            foreach ($post->getAttachments() as $attachment) {
                $this->storage->relocate($attachment, $wasPublicMedia);
            }
        }

        $post->markEdited();
        $this->em->flush();
        $this->storage->deleteFiles($filesToDelete);
        $this->notifyMentions($content, $post, $audience, $post, $previousMentions);

        return $this->sendJson($this->presenter->card($post, $audience));
    }

    /** Fichiers ajoutés à une publication existante, par son auteur. */
    public function addAttachments(string $id, Request $request): object
    {
        $audience = $this->audiences->current();
        $post = $this->findVisible($id, $audience);
        if ($post === null) {
            return $this->notFound();
        }
        if (!$audience->isSelf($post->getAuthor()) || !$post->isPublished() || !$audience->canWrite() || $post->isRepost()) {
            return $this->forbidden();
        }

        /** @var list<UploadedFile> $files */
        $files = array_values(array_filter((array) ($request->files->all('attachments') ?: []), static fn($f) => $f instanceof UploadedFile));
        if ($files === []) {
            return $this->sendViolations(['attachments' => 'Aucun fichier reçu.']);
        }
        if ($post->getAttachments()->count() + count($files) > self::MAX_ATTACHMENTS) {
            return $this->sendViolations(['attachments' => sprintf('%d fichiers maximum par publication.', self::MAX_ATTACHMENTS)]);
        }

        $position = max([-1, ...array_map(static fn(PostAttachment $a) => $a->getPosition(), $post->getAttachments()->toArray())]) + 1;
        try {
            foreach ($files as $file) {
                $post->addAttachment($this->storage->store($file, $post, $position++));
            }
        } catch (AttachmentRejectedException $e) {
            return $this->sendViolations(['attachments' => $e->getMessage()]);
        }

        $post->markEdited();
        $this->em->flush();

        return $this->sendJson($this->presenter->card($post, $audience));
    }

    /**
     * Republication vers le cercle d'amis, avec ou sans commentaire. On republie toujours l'original :
     * republier une republication renvoie à sa source.
     */
    public function repost(string $id, Request $request): object
    {
        $audience = $this->audiences->current();
        $target = $this->findVisible($id, $audience);
        $original = $target?->isRepost() ? $target->getOriginalPost() : $target;
        if ($original === null || !$audience->canViewPost($original)) {
            return $this->notFound();
        }
        if (!$audience->canWrite()) {
            return $this->sendJson(['message' => 'Votre compte ne peut pas publier pour le moment.'], Response::HTTP_FORBIDDEN);
        }
        if ($audience->isSelf($original->getAuthor()) || !$original->isRepostable()) {
            return $this->sendJson(['message' => 'Cette publication ne peut pas être republiée.'], Response::HTTP_FORBIDDEN);
        }
        if ($this->posts->findRepost($audience->user, $original) !== null) {
            return $this->sendJson(['message' => 'Vous avez déjà republié cette publication.'], Response::HTTP_CONFLICT);
        }

        $data = json_decode($request->getContent() ?: '{}', true);
        $content = is_array($data) && is_string($data['content'] ?? null) ? trim($data['content']) : '';
        if (mb_strlen($content) > 20000) {
            return $this->sendViolations(['content' => 'Le commentaire est trop long.']);
        }

        $repost = new Post($audience->user, $original->getSlug(), $content, PostVisibility::Friends);
        $repost->setOriginalPost($original);
        foreach ($this->parser->hashtags($content) as $name) {
            $repost->addTag($this->findOrCreateTag($name));
        }

        $this->em->persist($repost);
        $this->em->flush();
        $this->posts->refreshRepostsCount($original);
        $this->notifier->notify($original->getAuthor(), $audience->user, NotificationType::PostReposted, $original);
        $this->notifyMentions($content, $repost, $audience, $repost);

        return $this->sendJson($this->presenter->card($repost, $audience), Response::HTTP_CREATED);
    }

    /** Annule ma republication d'une publication, avec ou sans commentaire. */
    public function unrepost(string $id): object
    {
        $audience = $this->audiences->current();
        $original = Uuid::isValid($id) ? $this->posts->find(Uuid::fromString($id)) : null;
        $repost = $original !== null ? $this->posts->findRepost($audience->user, $original) : null;
        if ($repost === null) {
            return $this->notFound();
        }

        $repost->setStatus(ContentStatus::Deleted);
        $this->em->flush();
        $this->posts->refreshRepostsCount($original);

        return $this->sendJson(null, Response::HTTP_NO_CONTENT);
    }

    public function delete(string $id): object
    {
        $audience = $this->audiences->current();
        $post = $this->findVisible($id, $audience);
        if ($post === null) {
            return $this->notFound();
        }
        if (!$audience->isSelf($post->getAuthor()) && !$audience->isStaff()) {
            return $this->forbidden();
        }

        $post->setStatus(ContentStatus::Deleted);
        $this->em->flush();
        if ($post->getOriginalPost() !== null) {
            $this->posts->refreshRepostsCount($post->getOriginalPost());
        }

        return $this->sendJson(null, Response::HTTP_NO_CONTENT);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Engagement
    // ─────────────────────────────────────────────────────────────────────────

    public function toggleLike(string $id): object
    {
        $audience = $this->audiences->current();
        $post = $this->findVisible($id, $audience);
        if ($post === null) {
            return $this->notFound();
        }
        if (!$audience->canEngage($post)) {
            return $this->forbidden();
        }

        $liked = $this->toggleRow('post_like', $post, $audience);
        $this->posts->adjustCounter($post, 'likes', $liked ? 1 : -1);
        if ($liked) {
            $this->notifier->notify($post->getAuthor(), $audience->user, NotificationType::PostLiked, $post);
        }

        return $this->sendJson(['liked' => $liked, 'likesCount' => $post->getLikesCount()]);
    }

    /**
     * Enregistrer un post n'est pas une interaction publique : il suffit de pouvoir le lire.
     */
    public function toggleBookmark(string $id): object
    {
        $audience = $this->audiences->current();
        $post = $this->findVisible($id, $audience);
        if ($post === null) {
            return $this->notFound();
        }
        if ($audience->user === null) {
            return $this->forbidden();
        }

        $bookmarked = $this->toggleRow('bookmark', $post, $audience, ['is_public_collection' => 0]);
        $this->posts->adjustCounter($post, 'bookmarks', $bookmarked ? 1 : -1);

        return $this->sendJson(['bookmarked' => $bookmarked, 'bookmarksCount' => $post->getBookmarksCount()]);
    }

    public function listBookmarks(int $page, ?string $collection): object
    {
        $audience = $this->audiences->current();
        $result = $this->bookmarks->listFor($audience->user, $page, $collection);
        $ids = array_map(static fn(Bookmark $b) => $b->getPost()->getId(), $result['items']);

        return $this->sendJson([
            'items' => $this->presenter->cards($this->posts->findVisibleByIds($ids, $audience), $audience),
            'page' => $result['page'],
            'hasMore' => $result['hasMore'],
            'collections' => $this->bookmarks->collectionsOf($audience->user),
        ]);
    }

    /** Range une publication enregistrée dans une collection, privée par défaut. */
    public function updateBookmark(string $postId, Request $request): object
    {
        $audience = $this->audiences->current();
        $id = Uuid::isValid($postId) ? Uuid::fromString($postId) : null;
        $post = $id !== null ? $this->posts->find($id) : null;
        $bookmark = $post !== null ? $this->bookmarks->findOneBy(['post' => $post, 'user' => $audience->user]) : null;
        if ($bookmark === null) {
            return $this->notFound();
        }

        $data = json_decode($request->getContent(), true);
        $name = is_array($data) && is_string($data['collectionName'] ?? null) ? trim($data['collectionName']) : '';
        if (mb_strlen($name) > 100) {
            return $this->sendViolations(['collectionName' => 'Nom de collection trop long.']);
        }

        $bookmark->moveToCollection($name !== '' ? $name : null, (bool) ($data['isPublic'] ?? false));
        $this->em->flush();

        return $this->sendJson(['collectionName' => $bookmark->getCollectionName(), 'isPublic' => $bookmark->isPublicCollection()]);
    }

    /** Collection partagée d'un membre : uniquement si elle a été rendue publique. */
    public function publicCollection(string $username, string $collection, int $page): object
    {
        $owner = $this->em->getRepository(\Fagathe\GatherCore\Entity\User::class)->findOneBy(['username' => $username]);
        if ($owner === null) {
            return $this->notFound();
        }

        $audience = $this->audiences->current();
        $result = $this->bookmarks->listFor($owner, $page, $collection, onlyPublic: true);
        $ids = array_map(static fn(Bookmark $b) => $b->getPost()->getId(), $result['items']);

        return $this->sendJson([
            'items' => $this->presenter->cards($this->posts->findVisibleByIds($ids, $audience), $audience),
            'page' => $result['page'],
            'hasMore' => $result['hasMore'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Commentaires
    // ─────────────────────────────────────────────────────────────────────────

    public function listComments(string $id, int $page): object
    {
        $post = $this->findVisible($id, $this->audiences->current());
        if ($post === null) {
            return $this->notFound();
        }

        $result = $this->comments->listForPost($post, $page);
        $liked = $this->likedCommentIds($result['items'], $this->audiences->current());

        return $this->sendJson([
            'items' => array_map(static fn(Comment $c) => CommentOutput::from($c, isset($liked[$c->getId()->toBinary()])), $result['items']),
            'page' => $result['page'],
            'hasMore' => $result['hasMore'],
        ]);
    }

    public function addComment(string $id, Request $request): object
    {
        $audience = $this->audiences->current();
        $post = $this->findVisible($id, $audience);
        if ($post === null) {
            return $this->notFound();
        }
        if (!$audience->canEngage($post)) {
            return $this->forbidden();
        }

        $input = $this->readInput($request->getContent(), CommentInput::class);
        if ($this->isErrorResponse($input, CommentInput::class)) {
            return $input;
        }

        $parent = null;
        if ($input->parentId !== null && $input->parentId !== '') {
            $parent = $this->comments->find(Uuid::fromString($input->parentId));
            if ($parent === null || !$parent->getPost()->getId()->equals($post->getId())) {
                return $this->sendViolations(['parentId' => 'Commentaire parent introuvable.']);
            }
        }

        $comment = new Comment($post, $audience->user, trim($input->content), $parent);
        $this->em->persist($comment);
        $this->em->flush();
        $this->posts->adjustCounter($post, 'comments', 1);

        // Une réponse à l'auteur de la publication ne lui vaut qu'une notification
        if ($parent !== null) {
            $this->notifier->notify($parent->getAuthor(), $audience->user, NotificationType::CommentReplied, $comment);
        }
        if ($parent === null || !$parent->getAuthor()->getId()->equals($post->getAuthor()->getId())) {
            $this->notifier->notify($post->getAuthor(), $audience->user, NotificationType::PostCommented, $post);
        }
        $this->notifyMentions($comment->getContent(), $post, $audience, $comment);

        return $this->sendJson(CommentOutput::from($comment), Response::HTTP_CREATED);
    }

    public function deleteComment(string $commentId): object
    {
        $audience = $this->audiences->current();
        $comment = Uuid::isValid($commentId) ? $this->comments->find(Uuid::fromString($commentId)) : null;
        if ($comment === null || $comment->getStatus() !== ContentStatus::Published) {
            return $this->notFound();
        }
        if (!$audience->isSelf($comment->getAuthor()) && !$audience->isStaff()) {
            return $this->forbidden();
        }

        $comment->setStatus(ContentStatus::Deleted);
        $this->em->flush();
        $this->posts->adjustCounter($comment->getPost(), 'comments', -1);

        return $this->sendJson(null, Response::HTTP_NO_CONTENT);
    }

    /** Modification d'un commentaire par son auteur seul. */
    public function updateComment(string $commentId, Request $request): object
    {
        $audience = $this->audiences->current();
        $comment = Uuid::isValid($commentId) ? $this->comments->find(Uuid::fromString($commentId)) : null;
        if ($comment === null || !$comment->isPublished() || !$audience->canViewPost($comment->getPost())) {
            return $this->notFound();
        }
        if (!$audience->isSelf($comment->getAuthor()) || !$audience->canWrite()) {
            return $this->forbidden();
        }

        $input = $this->readInput($request->getContent(), CommentInput::class);
        if ($this->isErrorResponse($input, CommentInput::class)) {
            return $input;
        }
        if (trim($input->content) === '') {
            return $this->sendViolations(['content' => 'Le commentaire est vide.']);
        }

        $previousMentions = $this->parser->mentions($comment->getContent());
        $comment->setContent(trim($input->content))->markEdited();
        $this->em->flush();
        $this->notifyMentions($comment->getContent(), $comment->getPost(), $audience, $comment, $previousMentions);

        $liked = $this->likedCommentIds([$comment], $audience);

        return $this->sendJson(CommentOutput::from($comment, isset($liked[$comment->getId()->toBinary()])));
    }

    public function toggleCommentLike(string $commentId): object
    {
        $audience = $this->audiences->current();
        $comment = Uuid::isValid($commentId) ? $this->comments->find(Uuid::fromString($commentId)) : null;
        if ($comment === null || !$comment->isPublished() || !$audience->canViewPost($comment->getPost())) {
            return $this->notFound();
        }
        if (!$audience->canEngage($comment->getPost())) {
            return $this->forbidden();
        }

        $connection = $this->em->getConnection();
        $keys = ['comment' => $comment->getId()->toBinary(), 'user' => $audience->user->getId()->toBinary()];
        $liked = $connection->executeStatement('DELETE FROM comment_like WHERE comment_id = :comment AND user_id = :user', $keys) === 0;
        if ($liked) {
            $liked = $connection->executeStatement(
                'INSERT IGNORE INTO comment_like (id, comment_id, user_id, created_at) VALUES (:id, :comment, :user, :now)',
                [...$keys, 'id' => Uuid::v7()->toBinary(), 'now' => date('Y-m-d H:i:s')]
            ) > 0;
        }

        $connection->executeStatement(
            'UPDATE comment SET likes_count = (SELECT COUNT(*) FROM comment_like WHERE comment_id = :comment) WHERE id = :comment',
            ['comment' => $keys['comment']]
        );
        $this->em->refresh($comment);
        if ($liked) {
            $this->notifier->notify($comment->getAuthor(), $audience->user, NotificationType::CommentLiked, $comment);
        }

        return $this->sendJson(['liked' => $liked, 'likesCount' => $comment->getLikesCount()]);
    }

    /**
     * @param list<Comment> $comments
     *
     * @return array<string, true> commentaires aimés par le lecteur, indexés par identifiant binaire
     */
    public function likedCommentIds(array $comments, Audience $audience): array
    {
        if ($audience->user === null || $comments === []) {
            return [];
        }

        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT comment_id FROM comment_like WHERE user_id = :user AND comment_id IN (:comments)',
            ['user' => $audience->user->getId()->toBinary(), 'comments' => array_map(static fn(Comment $c) => $c->getId()->toBinary(), $comments)],
            ['comments' => \Doctrine\DBAL\ArrayParameterType::BINARY]
        );

        return array_fill_keys($ids, true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fichiers privés
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sert un fichier après contrôle d'accès. BinaryFileResponse gère les requêtes Range (HTTP 206).
     */
    public function media(string $attachmentId, bool $thumbnail): Response
    {
        $attachment = Uuid::isValid($attachmentId) ? $this->em->find(PostAttachment::class, Uuid::fromString($attachmentId)) : null;
        if ($attachment === null || !$this->audiences->current()->canViewPost($attachment->getPost())) {
            return new Response(null, Response::HTTP_NOT_FOUND);
        }

        $path = $this->storage->absolutePath($attachment, $thumbnail);
        if (!is_file($path)) {
            return new Response(null, Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($path, headers: ['Content-Type' => $thumbnail ? 'image/webp' : $attachment->getMimeType()]);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Jamais de cache partagé pour un contenu réservé
        $response->setPrivate();
        $response->setContentDisposition(
            $attachment->getType()->value === 'document' ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            'fichier.' . pathinfo($path, PATHINFO_EXTENSION)
        );

        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Outils
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array{items: list<Post>, page: int, hasMore: bool} $result
     *
     * @return array{items: list<\Fagathe\GatherCore\Dto\Output\PostCardOutput>, page: int, hasMore: bool}
     */
    public function page(array $result, Audience $audience): array
    {
        return [
            'items' => $this->presenter->cards($result['items'], $audience),
            'page' => $result['page'],
            'hasMore' => $result['hasMore'],
        ];
    }

    public static function uuidFromPath(string $slugAndId): ?Uuid
    {
        if (preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i', $slugAndId, $m) !== 1) {
            return null;
        }

        return Uuid::fromString($m[1]);
    }

    private function findVisible(string $id, Audience $audience): ?Post
    {
        $post = Uuid::isValid($id) ? $this->posts->find(Uuid::fromString($id)) : null;

        return $post !== null && $audience->canViewPost($post) ? $post : null;
    }

    /**
     * Prévient les membres mentionnés qui peuvent lire la publication ; une modification ne prévient que les nouveaux.
     *
     * @param list<string> $alreadyMentioned
     */
    private function notifyMentions(string $content, Post $post, Audience $audience, Post|Comment $target, array $alreadyMentioned = []): void
    {
        foreach ($this->parser->mentions($content) as $username) {
            if (in_array($username, $alreadyMentioned, true)) {
                continue;
            }
            $user = $this->users->findOneBy(['username' => $username]);
            if ($user !== null && $this->audiences->for($user)->canViewPost($post)) {
                $this->notifier->notify($user, $audience->user, NotificationType::UserMentioned, $target);
            }
        }
    }

    private function findOrCreateTag(string $name): Tag
    {
        $tag = $this->tags->findOneBy(['name' => $name]);
        if ($tag === null) {
            $tag = new Tag($name, $this->parser->slug($name, fallback: 'tag-' . substr(md5($name), 0, 8)));
            $this->em->persist($tag);
        }

        return $tag;
    }

    /**
     * Bascule une ligne (like, favori) : INSERT IGNORE s'appuie sur la contrainte UNIQUE,
     * deux clics simultanés ne peuvent donc pas créer de doublon ni fausser le compteur.
     *
     * @param array<string, mixed> $extra
     */
    private function toggleRow(string $table, Post $post, Audience $audience, array $extra = []): bool
    {
        $connection = $this->em->getConnection();
        $removed = $connection->executeStatement(
            sprintf('DELETE FROM %s WHERE post_id = :post AND user_id = :user', $table),
            ['post' => $post->getId()->toBinary(), 'user' => $audience->user->getId()->toBinary()]
        );
        if ($removed > 0) {
            return false;
        }

        return $this->insertIgnore($table, $post, $audience, 'created_at', $extra);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function insertIgnore(string $table, Post $post, Audience $audience, string $dateColumn, array $extra = []): bool
    {
        $values = ['id' => Uuid::v7()->toBinary(), 'post_id' => $post->getId()->toBinary(), 'user_id' => $audience->user->getId()->toBinary(), $dateColumn => date('Y-m-d H:i:s')] + $extra;
        $sql = sprintf(
            'INSERT IGNORE INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_keys($values)),
            implode(', ', array_fill(0, count($values), '?'))
        );

        return $this->em->getConnection()->executeStatement($sql, array_values($values)) > 0;
    }

    private function isNewAnonymousView(Post $post, Request $request): bool
    {
        $fingerprint = hash('sha256', $post->getId() . '|' . $request->getClientIp() . '|' . $request->headers->get('User-Agent'));
        $item = $this->cache->getItem('post_view_' . $fingerprint);
        if ($item->isHit()) {
            return false;
        }

        $item->set(true)->expiresAfter(self::ANONYMOUS_VIEW_TTL);
        $this->cache->save($item);

        return true;
    }

    private function notFound(): object
    {
        return $this->sendJson(['message' => 'Publication introuvable.'], Response::HTTP_NOT_FOUND);
    }

    private function forbidden(): object
    {
        return $this->sendJson(['message' => 'Action non autorisée.'], Response::HTTP_FORBIDDEN);
    }
}
