<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Faker\Generator;
use Fagathe\GatherCore\Entity\Bookmark;
use Fagathe\GatherCore\Entity\Comment;
use Fagathe\GatherCore\Entity\Conversation;
use Fagathe\GatherCore\Entity\Friendship;
use Fagathe\GatherCore\Entity\GroupMember;
use Fagathe\GatherCore\Entity\LinkPreview;
use Fagathe\GatherCore\Entity\Message;
use Fagathe\GatherCore\Entity\ModerationLog;
use Fagathe\GatherCore\Entity\Notification;
use Fagathe\GatherCore\Entity\Post;
use Fagathe\GatherCore\Entity\Report;
use Fagathe\GatherCore\Entity\SocialGroup;
use Fagathe\GatherCore\Entity\SupportMessage;
use Fagathe\GatherCore\Entity\SupportTicket;
use Fagathe\GatherCore\Entity\Tag;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Auth\UserRole;
use Fagathe\GatherCore\Enum\Auth\UserStatus;
use Fagathe\GatherCore\Enum\Moderation\ModerationActionType;
use Fagathe\GatherCore\Enum\Moderation\ReportReason;
use Fagathe\GatherCore\Enum\Moderation\ReportStatus;
use Fagathe\GatherCore\Enum\Social\ContentStatus;
use Fagathe\GatherCore\Enum\Social\FriendshipStatus;
use Fagathe\GatherCore\Enum\Social\GroupMemberStatus;
use Fagathe\GatherCore\Enum\Social\GroupRole;
use Fagathe\GatherCore\Enum\Social\GroupVisibility;
use Fagathe\GatherCore\Enum\Social\NotificationType;
use Fagathe\GatherCore\Enum\Social\PostVisibility;
use Fagathe\GatherCore\Enum\Social\ThemePalette;
use Fagathe\GatherCore\Enum\Support\TicketCategory;
use Fagathe\GatherCore\Enum\Support\TicketPriority;
use Fagathe\GatherCore\Enum\Support\TicketStatus;
use Fagathe\GatherCore\Service\AttachmentStorageService;
use Fagathe\GatherCore\Service\ContentParser;
use Fagathe\GatherCore\Service\PopularityCalculatorService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Jeu de démonstration, dev uniquement. Tous les comptes : mot de passe « Gather2026 ».
 * Le tirage est fixe : recharger les fixtures redonne le même réseau.
 */
class AppFixtures extends Fixture
{
    private const USERS = 80;
    private const POSTS = 420;
    private const RECENT_POSTS = 140;
    private const COMMENTS = 1400;
    private const POSTS_WITH_IMAGES = 70;
    private const CHUNK = 500;

    /** Tons des images générées : couleurs des palettes Gather */
    private const IMAGE_TONES = [[75, 63, 216], [154, 99, 68], [46, 125, 91], [200, 62, 101], [217, 119, 6], [110, 92, 230]];

    private Generator $faker;
    private Connection $connection;
    private \DateTimeImmutable $now;

    /** @var list<User> comptes pouvant publier */
    private array $users = [];

    /** @var array<string, User> comptes de démonstration, connus et documentés */
    private array $demo = [];

    /** @var array<string, SocialGroup> par slug */
    private array $groups = [];

    /** @var array<string, string> slug du groupe => thème */
    private array $groupThemes = [];

    /** @var array<string, list<User>> slug du groupe => membres validés */
    private array $members = [];

    /** @var array<string, true> paires déjà reliées */
    private array $friendPairs = [];

    /** @var array<string, Tag> */
    private array $tags = [];

    /** @var list<Post> */
    private array $posts = [];

    /** @var list<LinkPreview> */
    private array $previews = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ContentParser $parser,
        private readonly PopularityCalculatorService $popularity,
        private readonly AttachmentStorageService $storage,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    public function load(ObjectManager $manager): void
    {
        $this->faker = Factory::create('fr_FR');
        $this->faker->seed(2026);
        $this->connection = $this->em->getConnection();
        $this->now = new \DateTimeImmutable();

        $this->removeGeneratedFiles();
        $this->loadUsers();
        $this->loadFriendships();
        $this->loadGroups();
        $this->em->flush();

        $this->loadLinkPreviews();
        $this->loadPosts();
        $this->em->flush();

        $comments = $this->loadComments();
        $this->em->flush();

        $this->loadEngagement();
        $this->loadCommentLikes($comments);
        $reposts = $this->loadReposts();
        $this->em->flush();
        $this->loadNotifications($comments, $reposts);
        $this->loadBookmarks();
        $this->loadConversations();
        $this->loadReports($comments);
        $this->loadSupport();
        $this->em->flush();

        $this->refreshCounters();
        $this->popularity->recalculatePosts();
        $this->popularity->recalculateTags();
    }

    /** La base est purgée : les fichiers des fixtures précédentes n'ont plus aucun propriétaire. */
    private function removeGeneratedFiles(): void
    {
        $filesystem = new Filesystem();
        foreach (['public/uploads/posts', 'public/uploads/avatars', 'public/uploads/banners', 'var/private_media'] as $directory) {
            $filesystem->remove($this->projectDir . '/' . $directory);
        }
    }

    private function loadUsers(): void
    {
        $hash = $this->hasher->hashPassword(new User(), 'Gather2026');

        $this->demo['admin'] = $this->user($hash, 'admin', 'Alex', 'Admin', UserRole::ROLE_ADMIN);
        $this->demo['modo'] = $this->user($hash, 'modo', 'Mina', 'Modo', UserRole::ROLE_MODERATOR);
        $this->demo['clara'] = $this->user($hash, 'clara', 'Clara', 'Martin', bio: 'Photographe amatrice, toujours un boîtier à la main.');
        $this->demo['sam'] = $this->user($hash, 'sam', 'Sam', 'Durand', bio: 'Randonneur et amateur de café.');
        $this->demo['noor'] = $this->user($hash, 'noor', 'Noor', 'Haddad', bio: 'Musique, vinyles et concerts.');
        $this->demo['discret'] = $this->user($hash, 'discret', 'Dany', 'Discret')->setIsPrivate(true)->setIsExcludedFromSearch(true);
        $this->users = [$this->demo['clara'], $this->demo['sam'], $this->demo['noor'], $this->demo['discret']];

        for ($i = count($this->demo); $i < self::USERS; ++$i) {
            $firstname = $this->faker->firstName();
            $lastname = $this->faker->lastName();
            $username = sprintf('%s.%s%d', $this->parser->slug($firstname), $this->parser->slug($lastname), $i);

            $user = $this->user($hash, $username, $firstname, $lastname, bio: $this->faker->boolean(75) ? $this->faker->randomElement(FixtureContent::BIOS) : null);
            $isPrivate = $this->faker->boolean(10);
            $user->setCreatedAt($this->past(400, 30))->setIsPrivate($isPrivate)->setIsExcludedFromSearch($isPrivate && $this->faker->boolean(50));
            $this->users[] = $user;

            if ($this->faker->boolean(40)) {
                $user->setAvatar($this->storage->storePublicImage($this->image(320, 320, $user->getUsername()), 'avatars'));
            }
            if ($this->faker->boolean(15)) {
                $user->setBanner($this->storage->storePublicImage($this->image(1200, 360, $user->getUsername()), 'banners'));
            }
        }

        // Quelques états à voir dans le back-office, jamais sur les comptes de démonstration
        $sample = array_slice($this->users, 10, 8);
        $sample[0]->applySanction(UserStatus::ReadOnly, $this->now->modify('+2 days'))->addWarning();
        $sample[1]->applySanction(UserStatus::Suspended, $this->now->modify('+12 days'))->addWarning();
        $sample[2]->applySanction(UserStatus::Banned);
        $sample[3]->addWarning();
        $sample[4]->setIsVerified(false)->setEmailVerifiedAt(null);
    }

    private function loadFriendships(): void
    {
        $clara = $this->demo['clara'];
        $sam = $this->demo['sam'];
        $this->friendship($clara, $sam, FriendshipStatus::Accepted);
        $this->friendship($this->demo['noor'], $clara, FriendshipStatus::Pending);
        $this->friendship($this->demo['discret'], $sam, FriendshipStatus::Accepted);

        // Comptes de démonstration : de vraies listes d'amis, et des demandes à traiter
        foreach ($this->demo as $account) {
            $others = $this->faker->randomElements($this->users, $this->faker->numberBetween(18, 40));
            foreach ($others as $index => $other) {
                $this->friendship($account, $other, $index % 8 === 0 ? FriendshipStatus::Pending : FriendshipStatus::Accepted);
            }
        }

        foreach ($this->users as $user) {
            foreach ($this->faker->randomElements($this->users, $this->faker->numberBetween(2, 9)) as $other) {
                $status = $this->faker->randomElement([FriendshipStatus::Accepted, FriendshipStatus::Accepted, FriendshipStatus::Accepted, FriendshipStatus::Pending]);
                $this->friendship($user, $other, $status);
            }
        }
    }

    private function loadGroups(): void
    {
        foreach (FixtureContent::THEMES as $theme => $data) {
            $this->group($data['name'], $theme, $data['description'], $data['palette'], $data['icon'], GroupVisibility::Public);
        }
        foreach (FixtureContent::PRIVATE_GROUPS as $data) {
            $this->group($data['name'], $data['theme'], $data['description'], $data['palette'], $data['icon'], GroupVisibility::Private);
        }
    }

    private function group(string $name, string $theme, string $description, string $palette, string $icon, GroupVisibility $visibility): void
    {
        $isPublic = $visibility === GroupVisibility::Public;
        $owner = $this->faker->randomElement($this->users);
        $group = new SocialGroup($name, $this->parser->slug($name), $owner);
        $group->setDescription($description)
            ->setThemePalette(ThemePalette::from($palette))
            ->setIconName($icon)
            ->setVisibility($visibility);
        $this->backdate($group, $this->past(500, 200));
        $this->em->persist($group);
        $this->em->persist(new GroupMember($group, $owner, GroupRole::Owner));

        $slug = $group->getSlug();
        $this->groups[$slug] = $group;
        $this->groupThemes[$slug] = $theme;
        $this->members[$slug] = [$owner];

        $count = $this->faker->numberBetween($isPublic ? 12 : 6, $isPublic ? 45 : 14);
        foreach ($this->faker->randomElements($this->users, $count) as $index => $user) {
            if ($user === $owner) {
                continue;
            }

            // Un responsable par communauté, et quelques demandes en attente dans les groupes privés
            if (!$isPublic && $this->faker->boolean(15)) {
                $this->em->persist(new GroupMember($group, $user, GroupRole::Member, GroupMemberStatus::Pending));
                continue;
            }
            $this->em->persist(new GroupMember($group, $user, $index === 0 ? GroupRole::Moderator : GroupRole::Member));
            $this->members[$slug][] = $user;
        }

        // Les comptes de démonstration ont de quoi publier et une communauté privée à ouvrir
        if ($slug === 'photographie' || $slug === 'cercle-argentique') {
            foreach ([$this->demo['clara'], $this->demo['sam']] as $user) {
                if (!in_array($user, $this->members[$slug], true)) {
                    $this->em->persist(new GroupMember($group, $user));
                    $this->members[$slug][] = $user;
                }
            }
        }
    }

    private function loadLinkPreviews(): void
    {
        foreach (FixtureContent::LINKS as [$url, $domain, $type, $title, $description, $site]) {
            $preview = new LinkPreview($url, $domain, $type, $title, $description, null, $site);
            $this->em->persist($preview);
            $this->previews[] = $preview;
        }
    }

    private function loadPosts(): void
    {
        $slugs = array_keys($this->groups);
        $themes = array_keys(FixtureContent::THEMES);

        for ($i = 0; $i < self::POSTS; ++$i) {
            // Les premières sont récentes : elles alimentent l'onglet Tendances (7 jours glissants)
            $date = $i < self::RECENT_POSTS ? $this->past(7) : $this->past(240, 7);

            if ($this->faker->boolean(60)) {
                $slug = $this->faker->randomElement($slugs);
                $group = $this->groups[$slug];
                $theme = $this->groupThemes[$slug];
                $author = $this->faker->randomElement($this->members[$slug]);
                $visibility = PostVisibility::Group;
            } else {
                $group = null;
                $theme = $this->faker->randomElement($themes);
                $author = $this->faker->randomElement($this->users);
                $visibility = $this->faker->boolean(80) ? PostVisibility::Public : PostVisibility::Friends;
            }

            $post = $this->post($author, $theme, $visibility, $group, $date);
            if ($i < self::POSTS_WITH_IMAGES) {
                $this->attachImages($post);
            }
            $this->em->persist($post);
            $this->posts[] = $post;

            if ($i % 100 === 99) {
                $this->em->flush();
            }
        }

        // Publications connues des comptes de démonstration, pour disposer de contenus reconnaissables
        $this->demoPost($this->demo['clara'], 'Bienvenue sur Gather ! Les passions se partagent. #gather');
        $this->demoPost($this->demo['clara'], 'Lumière dorée ce matin sur les quais, qui vient à la sortie #photo de samedi ? @sam', 'photographie');
        $this->demoPost($this->demo['clara'], 'Pellicule développée hier soir, à montrer au cercle uniquement. #argentique', 'cercle-argentique');
        $this->demoPost($this->demo['sam'], 'Petit message pour mes amis : on se voit vendredi ?', visibility: PostVisibility::Friends);
        $this->demoPost($this->demo['discret'], 'Compte privé : ce message reste réservé à mes amis.');
    }

    private function demoPost(User $author, string $content, ?string $groupSlug = null, PostVisibility $visibility = PostVisibility::Public): void
    {
        $group = $groupSlug !== null ? $this->groups[$groupSlug] : null;
        $post = new Post($author, $this->parser->slug($content), $content, $group !== null ? PostVisibility::Group : $visibility);
        $post->setGroup($group);
        $this->addTags($post);
        $this->backdate($post, $this->past(2));
        $this->em->persist($post);
        $this->posts[] = $post;
    }

    private function post(User $author, string $theme, PostVisibility $visibility, ?SocialGroup $group, \DateTimeImmutable $date): Post
    {
        $data = FixtureContent::THEMES[$theme];
        $f = $this->faker;

        $parts = [$f->randomElement($data['openers'])];
        foreach ($f->randomElements($data['details'], $f->numberBetween(0, 2)) as $detail) {
            $parts[] = $detail;
        }
        if ($f->boolean(55)) {
            $parts[] = $f->randomElement($data['questions']);
        }
        // Les longs textes déclenchent « Lire la suite »
        if ($f->boolean(10)) {
            $parts[] = "\n\n" . implode(' ', $f->randomElements($data['details'], min(3, count($data['details']))));
            $parts[] = "\n\n" . $f->realText(320);
        }
        if ($f->boolean(25)) {
            $parts[] = '@' . $f->randomElement($this->users)->getUsername();
        }
        $parts[] = implode(' ', array_map(static fn(string $tag) => '#' . $tag, $f->randomElements($data['tags'], $f->numberBetween(1, 2))));

        $content = implode(' ', $parts);
        $title = $f->boolean(18) ? $f->randomElement($data['titles']) : null;

        $post = new Post($author, $this->parser->slug($title ?? $content), $content, $visibility);
        $post->setTitle($title)->setGroup($group);
        if ($f->boolean(20)) {
            $post->setLocationName($f->randomElement($data['places']));
        }
        if ($f->boolean(8)) {
            $post->setLinkPreview($f->randomElement($this->previews));
        }
        // Quelques contenus retirés, pour la modération et les listes
        if ($f->boolean(2)) {
            $post->setStatus(ContentStatus::Deleted);
        }

        $this->addTags($post);
        $this->backdate($post, $date);

        return $post;
    }

    private function addTags(Post $post): void
    {
        foreach ($this->parser->hashtags($post->getContent()) as $name) {
            if (!isset($this->tags[$name])) {
                $this->tags[$name] = new Tag($name, $this->parser->slug($name, fallback: 'tag-' . substr(md5($name), 0, 8)));
                $this->em->persist($this->tags[$name]);
            }
            $post->addTag($this->tags[$name]);
        }
    }

    private function attachImages(Post $post): void
    {
        $count = $this->faker->randomElement([1, 1, 1, 2, 2, 3, 4]);
        for ($position = 0; $position < $count; ++$position) {
            $post->addAttachment($this->storage->store($this->image(1200, 800, 'photo-' . $position), $post, $position));
        }
    }

    /**
     * Fausse photo : un dégradé et quelques formes, dans les tons des palettes.
     */
    private function image(int $width, int $height, string $name): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        [$r, $g, $b] = $this->faker->randomElement(self::IMAGE_TONES);
        [$r2, $g2, $b2] = $this->faker->randomElement(self::IMAGE_TONES);

        for ($y = 0; $y < $height; ++$y) {
            $ratio = $y / max(1, $height - 1);
            $color = imagecolorallocate(
                $image,
                (int) ($r + ($r2 - $r) * $ratio),
                (int) ($g + ($g2 - $g) * $ratio),
                (int) ($b + ($b2 - $b) * $ratio),
            );
            imageline($image, 0, $y, $width - 1, $y, $color);
        }

        for ($i = 0; $i < 7; ++$i) {
            $color = imagecolorallocatealpha($image, 255, 255, 255, $this->faker->numberBetween(90, 118));
            $size = $this->faker->numberBetween((int) ($height / 8), (int) ($height / 1.5));
            imagefilledellipse($image, $this->faker->numberBetween(0, $width), $this->faker->numberBetween(0, $height), $size, $size, $color);
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'fixture');
        imagepng($image, $path);

        return new UploadedFile($path, $name . '.png', 'image/png', test: true);
    }

    /**
     * @return list<Comment>
     */
    private function loadComments(): array
    {
        $visible = array_values(array_filter($this->posts, static fn(Post $post) => $post->isPublished()));
        $comments = [];
        $byPost = [];

        for ($i = 0; $i < self::COMMENTS; ++$i) {
            $post = $this->faker->randomElement($visible);
            $key = (string) $post->getId();
            $group = $post->getGroup();
            $author = $this->faker->randomElement($group !== null && !$group->isPublic() ? $this->members[$group->getSlug()] : $this->users);
            $parent = isset($byPost[$key]) && $this->faker->boolean(25) ? $this->faker->randomElement($byPost[$key]) : null;

            $comment = new Comment($post, $author, $this->faker->randomElement(FixtureContent::COMMENTS), $parent);
            $this->backdate($comment, $this->after($post->getCreatedAt()));
            $this->em->persist($comment);
            $comments[] = $comment;
            $byPost[$key][] = $comment;

            if ($i % 300 === 299) {
                $this->em->flush();
            }
        }

        return $comments;
    }

    /** Likes et vues : plusieurs milliers de lignes, insérées directement. */
    private function loadEngagement(): void
    {
        $likes = [];
        $views = [];

        foreach ($this->posts as $post) {
            if (!$post->isPublished()) {
                continue;
            }

            $group = $post->getGroup();
            $audience = $group !== null && !$group->isPublic() ? $this->members[$group->getSlug()] : $this->users;

            foreach ($this->faker->randomElements($audience, min(count($audience), $this->faker->numberBetween(0, 22))) as $user) {
                $likes[] = [Uuid::v7()->toBinary(), $post->getId()->toBinary(), $user->getId()->toBinary(), $this->after($post->getCreatedAt())->format('Y-m-d H:i:s')];
            }
            foreach ($this->faker->randomElements($audience, min(count($audience), $this->faker->numberBetween(2, 60))) as $user) {
                $views[] = [Uuid::v7()->toBinary(), $post->getId()->toBinary(), $user->getId()->toBinary(), $this->after($post->getCreatedAt())->format('Y-m-d H:i:s')];
            }
        }

        $this->bulkInsert('post_like', ['id', 'post_id', 'user_id', 'created_at'], $likes);
        $this->bulkInsert('post_view', ['id', 'post_id', 'user_id', 'viewed_at'], $views);
    }

    /** @param list<Comment> $comments */
    private function loadCommentLikes(array $comments): void
    {
        $likes = [];
        foreach ($comments as $comment) {
            $group = $comment->getPost()->getGroup();
            $audience = $group !== null && !$group->isPublic() ? $this->members[$group->getSlug()] : $this->users;
            foreach ($this->faker->randomElements($audience, min(count($audience), $this->faker->numberBetween(0, 6))) as $user) {
                $likes[] = [Uuid::v7()->toBinary(), $comment->getId()->toBinary(), $user->getId()->toBinary(), $this->after($comment->getCreatedAt())->format('Y-m-d H:i:s')];
            }
        }

        $this->bulkInsert('comment_like', ['id', 'comment_id', 'user_id', 'created_at'], $likes);
    }

    /**
     * Republications vers le fil « Réseau » : une par membre et par publication d'origine, jamais la sienne.
     *
     * @return list<Post>
     */
    private function loadReposts(): array
    {
        $repostable = array_values(array_filter($this->posts, static fn(Post $post) => $post->isRepostable()));
        $reposts = [];
        $pairs = [];

        foreach ($this->faker->randomElements($repostable, min(count($repostable), 70)) as $original) {
            $reposter = $this->faker->randomElement($this->users);
            $key = $reposter->getUsername() . '|' . $original->getId();
            if ($reposter === $original->getAuthor() || isset($pairs[$key])) {
                continue;
            }
            $pairs[$key] = true;

            $content = $this->faker->boolean(40) ? $this->faker->randomElement(FixtureContent::COMMENTS) : '';
            $repost = new Post($reposter, $original->getSlug(), $content, PostVisibility::Friends);
            $repost->setOriginalPost($original);
            $this->backdate($repost, $this->after($original->getCreatedAt()));
            $this->em->persist($repost);
            $reposts[] = $repost;
        }

        return $reposts;
    }

    /**
     * Notifications tirées des commentaires et républications du jeu, regroupées comme en production :
     * une par destinataire, type et cible. Les plus anciennes sont déjà lues.
     *
     * @param list<Comment> $comments
     * @param list<Post>    $reposts
     */
    private function loadNotifications(array $comments, array $reposts): void
    {
        $events = [];
        foreach ($comments as $comment) {
            $parent = $comment->getParentComment();
            [$recipient, $type, $targetType, $target] = $parent !== null && $parent->getAuthor() !== $comment->getAuthor()
                ? [$parent->getAuthor(), NotificationType::CommentReplied, 'comment', $parent]
                : [$comment->getPost()->getAuthor(), NotificationType::PostCommented, 'post', $comment->getPost()];
            $events[] = [$recipient, $comment->getAuthor(), $type, $targetType, $target, $comment->getCreatedAt()];
        }
        foreach ($reposts as $repost) {
            $original = $repost->getOriginalPost();
            $events[] = [$original->getAuthor(), $repost->getAuthor(), NotificationType::PostReposted, 'post', $original, $repost->getCreatedAt()];
        }
        usort($events, static fn(array $a, array $b) => $a[5] <=> $b[5]);

        /** @var array<string, Notification> $grouped */
        $grouped = [];
        foreach ($events as [$recipient, $actor, $type, $targetType, $target, $date]) {
            if ($recipient === $actor) {
                continue;
            }
            $key = $recipient->getUsername() . '|' . $type->value . '|' . $target->getId();
            if (isset($grouped[$key])) {
                $grouped[$key]->addActor($actor);
            } else {
                $grouped[$key] = new Notification($recipient, $actor, $type, $targetType, (string) $target->getId());
                $this->backdate($grouped[$key], $date);
                $this->em->persist($grouped[$key]);
            }
            $this->setProperty($grouped[$key], 'updatedAt', $date);
        }

        $readBefore = $this->now->modify('-2 days');
        foreach ($grouped as $notification) {
            if ($notification->getUpdatedAt() < $readBefore) {
                $this->setProperty($notification, 'readAt', $notification->getUpdatedAt());
            }
        }
    }

    private function loadBookmarks(): void
    {
        $visible = array_values(array_filter($this->posts, static fn(Post $post) => $post->isPublished() && $post->hasPublicMedia()));
        $collections = ['À refaire', 'Idées de sorties', 'Inspirations', 'À lire plus tard'];

        foreach ($this->users as $user) {
            foreach ($this->faker->randomElements($visible, $this->faker->numberBetween(0, 12)) as $post) {
                $bookmark = new Bookmark($post, $user);
                if ($this->faker->boolean(45)) {
                    $bookmark->moveToCollection($this->faker->randomElement($collections), $this->faker->boolean(25));
                }
                $this->backdate($bookmark, $this->after($post->getCreatedAt()));
                $this->em->persist($bookmark);
            }
        }
    }

    private function loadConversations(): void
    {
        $contactable = array_values(array_filter($this->users, static fn(User $user) => !$user->isPrivate() && $user->getEffectiveStatus() === UserStatus::Active));

        // Les comptes de démonstration ont des conversations à ouvrir dès la connexion
        $this->conversation([$this->demo['clara'], $this->demo['sam']], null);
        $this->conversation([$this->demo['clara'], $this->demo['noor']], null);
        $this->conversation([$this->demo['clara'], $this->demo['sam'], $this->demo['noor']], 'Sortie de samedi');

        for ($i = 0; $i < 40; ++$i) {
            $isGroup = $this->faker->boolean(20);
            $people = $this->faker->randomElements($contactable, $isGroup ? $this->faker->numberBetween(3, 6) : 2);
            $this->conversation($people, $isGroup ? $this->faker->randomElement(['Organisation du week-end', 'Projet commun', 'Sortie du mois']) : null);
        }
    }

    /**
     * @param list<User> $people
     */
    private function conversation(array $people, ?string $title): void
    {
        $conversation = new Conversation($title);
        foreach ($people as $person) {
            $conversation->addParticipant($person);
        }
        $this->em->persist($conversation);

        $date = $this->past(30, 1);
        $count = $this->faker->numberBetween(3, 16);
        $last = null;
        for ($i = 0; $i < $count; ++$i) {
            $date = $date->modify(sprintf('+%d minutes', $this->faker->numberBetween(2, 300)));
            $date = $date > $this->now ? $this->now : $date;
            $last = $this->faker->randomElement($people);
            $message = new Message($conversation, $last, $this->faker->randomElement(FixtureContent::MESSAGES));
            $this->backdate($message, $date);
            $this->em->persist($message);
        }

        $this->setProperty($conversation, 'updatedAt', $date);

        foreach ($conversation->getParticipants() as $participant) {
            // L'auteur du dernier message a tout lu ; les autres, pas forcément
            if ($participant->getUser() === $last || $this->faker->boolean(60)) {
                $participant->markRead();
                continue;
            }
            for ($i = $this->faker->numberBetween(1, 4); $i > 0; --$i) {
                $participant->incrementUnread();
            }
        }
    }

    /**
     * @param list<Comment> $comments
     */
    private function loadReports(array $comments): void
    {
        $posts = array_values(array_filter($this->posts, static fn(Post $post) => $post->isPublished()));
        $moderator = $this->demo['modo'];

        // En attente
        foreach ($this->faker->randomElements($posts, 22) as $post) {
            $this->report($this->reporterFor($post->getAuthor()), $post);
        }
        foreach ($this->faker->randomElements($comments, 12) as $comment) {
            $this->report($this->reporterFor($comment->getAuthor()), $comment);
        }
        foreach ($this->faker->randomElements($this->users, 6) as $user) {
            $this->report($this->reporterFor($user), $user);
        }

        // Masqués préventivement : au moins 5 signalements distincts
        foreach ($this->faker->randomElements($posts, 6) as $post) {
            $post->setStatus(ContentStatus::AutoHidden);
            foreach ($this->faker->randomElements($this->users, 7) as $reporter) {
                if ($reporter !== $post->getAuthor()) {
                    $this->report($reporter, $post)->setStatus(ReportStatus::AutoHidden);
                }
            }
        }

        // Déjà traités, avec leur trace dans le journal
        $decisions = [
            [ModerationActionType::Dismiss, null, 'Signalement non fondé.'],
            [ModerationActionType::DeleteContent, null, 'Contenu contraire aux règles.'],
            [ModerationActionType::Warning, null, 'Premier avertissement.'],
            [ModerationActionType::ReadOnly, 3, 'Propos irrespectueux répétés.'],
            [ModerationActionType::Suspension, 7, 'Récidive après avertissement.'],
            [ModerationActionType::Ban, null, 'Spam massif.'],
        ];
        foreach ($this->faker->randomElements($posts, 18) as $post) {
            [$action, $days, $reason] = $this->faker->randomElement($decisions);
            $date = $this->past(60, 1);
            $report = $this->report($this->reporterFor($post->getAuthor()), $post);
            $report->resolve($moderator);
            $this->setProperty($report, 'resolvedAt', $date);

            $log = new ModerationLog($moderator, $action, $reason, $post->getAuthor(), $days);
            $this->backdate($log, $date);
            $this->em->persist($log);
        }
    }

    private function reporterFor(User $target): User
    {
        do {
            $reporter = $this->faker->randomElement($this->users);
        } while ($reporter === $target);

        return $reporter;
    }

    private function report(User $reporter, Post|Comment|User $target): Report
    {
        $key = spl_object_id($reporter) . '-' . spl_object_id($target);
        if (isset($this->reports[$key])) {
            return $this->reports[$key];
        }

        $report = new Report($reporter, $target, $this->faker->randomElement(ReportReason::cases()), $this->faker->randomElement(FixtureContent::REPORT_DETAILS));
        $this->backdate($report, $this->past(14));
        $this->em->persist($report);
        $this->reports[$key] = $report;

        return $report;
    }

    /** @var array<string, Report> une seule ligne par couple signaleur / cible (contrainte UNIQUE) */
    private array $reports = [];

    private function loadSupport(): void
    {
        $staff = [$this->demo['modo'], $this->demo['admin']];

        for ($i = 0; $i < 28; ++$i) {
            [$category, $subject, $message] = $this->faker->randomElement(FixtureContent::TICKETS);
            $member = $i === 0 ? $this->demo['clara'] : $this->faker->randomElement($this->users);
            $date = $this->past(45);

            $ticket = new SupportTicket(sprintf('#TCK-%s-%04X', $date->format('Y'), $i + 1), $member, TicketCategory::from($category), $subject);
            $ticket->setPriority($this->faker->randomElement(TicketPriority::cases()));
            $this->backdate($ticket, $date);
            $this->em->persist($ticket);

            $first = new SupportMessage($ticket, $member, $message);
            $this->backdate($first, $date);
            $this->em->persist($first);

            // Environ un ticket sur quatre attend encore une première réponse
            if ($this->faker->boolean(25)) {
                continue;
            }

            $agent = $this->faker->randomElement($staff);
            $date = $this->after($date);
            $ticket->setAssignedTo($agent)->setStatus($this->faker->randomElement([TicketStatus::InProgress, TicketStatus::WaitingUser, TicketStatus::Resolved]));

            $reply = new SupportMessage($ticket, $agent, $this->faker->randomElement(FixtureContent::STAFF_REPLIES));
            $this->backdate($reply, $date);
            $this->em->persist($reply);

            if ($this->faker->boolean(40)) {
                $note = new SupportMessage($ticket, $agent, $this->faker->randomElement(FixtureContent::INTERNAL_NOTES), true);
                $this->backdate($note, $date);
                $this->em->persist($note);
            }

            $this->setProperty($ticket, 'updatedAt', $date);
        }
    }

    /** Les compteurs dérivés sont recalculés d'un coup : les lignes sources font foi. */
    private function refreshCounters(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            UPDATE post p SET
                likes_count = (SELECT COUNT(*) FROM post_like l WHERE l.post_id = p.id),
                views_count = (SELECT COUNT(*) FROM post_view v WHERE v.post_id = p.id),
                comments_count = (SELECT COUNT(*) FROM comment c WHERE c.post_id = p.id AND c.status = 'published'),
                bookmarks_count = (SELECT COUNT(*) FROM bookmark b WHERE b.post_id = p.id),
                reports_count = (SELECT COUNT(*) FROM report r WHERE r.reported_post_id = p.id)
            SQL);
        // Même table en lecture et en écriture : MySQL exige une table dérivée
        $this->connection->executeStatement(<<<'SQL'
            UPDATE post p LEFT JOIN (SELECT original_post_id, COUNT(*) AS total FROM post WHERE is_repost = 1 AND status = 'published' GROUP BY original_post_id) r ON r.original_post_id = p.id
            SET p.reposts_count = COALESCE(r.total, 0)
            SQL);
        $this->connection->executeStatement('UPDATE comment c SET likes_count = (SELECT COUNT(*) FROM comment_like l WHERE l.comment_id = c.id)');
        $this->connection->executeStatement("UPDATE social_group g SET members_count = (SELECT COUNT(*) FROM group_member m WHERE m.group_id = g.id AND m.status = 'joined')");
        $this->connection->executeStatement('UPDATE comment c SET reports_count = (SELECT COUNT(*) FROM report r WHERE r.reported_comment_id = c.id)');
    }

    /**
     * @param list<string>       $columns
     * @param list<list<string>> $rows
     */
    private function bulkInsert(string $table, array $columns, array $rows): void
    {
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $this->connection->executeStatement(
                sprintf('INSERT IGNORE INTO %s (%s) VALUES %s', $table, implode(', ', $columns), implode(', ', array_fill(0, count($chunk), $placeholders))),
                array_merge(...$chunk)
            );
        }
    }

    private function user(string $hash, string $username, string $firstname, string $lastname, UserRole $role = UserRole::ROLE_USER, ?string $bio = null): User
    {
        $user = (new User())
            ->setEmail($username . '@gather.test')
            ->setUsername($username)
            ->setFirstname($firstname)
            ->setLastname($lastname)
            ->setRole($role)
            ->setBio($bio)
            ->setIsVerified(true)
            ->setEmailVerifiedAt(new \DateTimeImmutable())
            ->setPassword($hash);
        $this->em->persist($user);

        return $user;
    }

    private function friendship(User $a, User $b, FriendshipStatus $status): void
    {
        $ids = [(string) $a->getId(), (string) $b->getId()];
        sort($ids);
        $key = implode('|', $ids);
        if ($a === $b || isset($this->friendPairs[$key])) {
            return;
        }

        $this->friendPairs[$key] = true;
        $friendship = new Friendship($a, $b);
        $friendship->setStatus($status);
        $this->backdate($friendship, $this->past(200));
        $this->em->persist($friendship);
    }

    /** Instant aléatoire, entre $maxDays et $minDays avant maintenant. */
    private function past(int $maxDays, int $minDays = 0): \DateTimeImmutable
    {
        return $this->now->modify(sprintf('-%d minutes', $this->faker->numberBetween($minDays * 1440 + 5, $maxDays * 1440)));
    }

    /** Instant aléatoire postérieur à une date, sans dépasser maintenant. */
    private function after(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $minutes = max(1, (int) (($this->now->getTimestamp() - $date->getTimestamp()) / 60));

        return $date->modify(sprintf('+%d minutes', $this->faker->numberBetween(1, min($minutes, 7200))));
    }

    /** Les entités fixent leur date à la construction : seul le jeu de démonstration a besoin de la changer. */
    private function backdate(object $entity, \DateTimeImmutable $date): void
    {
        $this->setProperty($entity, 'createdAt', $date);
    }

    private function setProperty(object $entity, string $property, mixed $value): void
    {
        (new \ReflectionProperty($entity, $property))->setValue($entity, $value);
    }
}
