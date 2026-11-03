<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Fagathe\GatherCore\Dto\Input\SearchQueryInput;
use Fagathe\GatherCore\Dto\Output\GroupCardOutput;
use Fagathe\GatherCore\Dto\Output\UserSummaryOutput;
use Fagathe\GatherCore\Entity\SocialGroup;
use Fagathe\GatherCore\Entity\Tag;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Repository\PostRepository;
use Fagathe\GatherCore\Repository\SocialGroupRepository;
use Fagathe\GatherCore\Repository\TagRepository;
use Fagathe\GatherCore\Repository\UserRepository;
use Fagathe\GatherCore\Security\Audience;
use Symfony\Component\Uid\Uuid;

/**
 * Recherche hybride de la section 2.7 : FULLTEXT en mode booléen à partir de 3 caractères, LIKE préfixe en dessous.
 */
final class SearchService
{
    private const LIMIT = 20;
    private const FULLTEXT_MIN_LENGTH = 3;
    // Marge avant filtrage par droits d'accès : une partie des résultats peut être écartée ensuite
    private const POST_CANDIDATES = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly SocialGroupRepository $groups,
        private readonly PostRepository $posts,
        private readonly TagRepository $tags,
        private readonly PostPresenter $presenter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function search(SearchQueryInput $input, Audience $audience): array
    {
        $q = trim($input->q);
        $results = ['users' => [], 'groups' => [], 'posts' => [], 'tags' => $this->topTags()];
        if ($q === '') {
            return $results;
        }

        $tagPrefix = ltrim($q, '#');
        if ($tagPrefix !== '') {
            $results['matchingTags'] = array_map(
                static fn(Tag $tag) => ['name' => $tag->getName(), 'slug' => $tag->getSlug(), 'score' => $tag->getTrendingScore()],
                $this->tags->searchByPrefix($tagPrefix)
            );
        }

        $boolean = self::toBooleanQuery($q);
        $wants = static fn(string $type) => $input->type === 'all' || $input->type === $type;

        if ($wants('users')) {
            $results['users'] = array_map(UserSummaryOutput::from(...), $this->searchUsers($q, $boolean, $audience));
        }
        if ($wants('groups')) {
            $results['groups'] = array_map(GroupCardOutput::from(...), $this->searchGroups($q, $boolean, $audience));
        }
        if ($wants('posts')) {
            $results['posts'] = $this->presenter->cards($this->searchPosts($q, $boolean, $audience), $audience);
        }

        return $results;
    }

    /**
     * Sans caractère réservé du mode booléen ni mot trop court pour l'index : « +mot* » pour chaque mot.
     * Retourne null si aucun mot n'est exploitable, ce qui déclenche le repli sur LIKE.
     */
    public static function toBooleanQuery(string $q): ?string
    {
        if (mb_strlen($q) < self::FULLTEXT_MIN_LENGTH) {
            return null;
        }

        $clean = preg_replace('/[+\-><()~*"@%]+/u', ' ', $q) ?? '';
        $terms = array_filter(
            preg_split('/\s+/u', trim($clean), flags: PREG_SPLIT_NO_EMPTY) ?: [],
            static fn(string $term) => mb_strlen($term) >= self::FULLTEXT_MIN_LENGTH
        );

        return $terms === [] ? null : implode(' ', array_map(static fn(string $term) => '+' . $term . '*', $terms));
    }

    /** @return list<User> */
    private function searchUsers(string $q, ?string $boolean, Audience $audience): array
    {
        $params = [];
        $types = [];
        $match = $this->matchClause('username, firstname, lastname, bio', 'username', $q, $boolean, $params);

        // Un compte exclu de la recherche reste trouvable par ses amis actuels
        $privacy = 'u.is_excluded_from_search = 0';
        if ($audience->friendIds !== []) {
            $privacy = '(u.is_excluded_from_search = 0 OR u.id IN (:friends))';
            $params['friends'] = self::binary($audience->friendIds);
            $types['friends'] = ArrayParameterType::BINARY;
        }

        $sql = sprintf(
            "SELECT u.id FROM users u WHERE %s AND %s AND u.status != 'banned' AND u.deactivated_at IS NULL AND u.is_verified = 1 LIMIT %d",
            $match,
            $privacy,
            self::LIMIT
        );
        $ids = $this->em->getConnection()->fetchFirstColumn($sql, $params, $types);

        return $this->hydrate($this->users, $ids);
    }

    /** @return list<SocialGroup> */
    private function searchGroups(string $q, ?string $boolean, Audience $audience): array
    {
        $params = [];
        $types = [];
        $match = $this->matchClause('name, description', 'name', $q, $boolean, $params);

        $visibility = "g.visibility = 'public'";
        if ($audience->groupIds !== []) {
            $visibility = "(g.visibility = 'public' OR g.id IN (:mine))";
            $params['mine'] = self::binary($audience->groupIds);
            $types['mine'] = ArrayParameterType::BINARY;
        }

        $ids = $this->em->getConnection()->fetchFirstColumn(
            sprintf('SELECT g.id FROM social_group g WHERE %s AND %s LIMIT %d', $match, $visibility, self::LIMIT),
            $params,
            $types
        );

        return $this->hydrate($this->groups, $ids);
    }

    /** @return list<\Fagathe\GatherCore\Entity\Post> */
    private function searchPosts(string $q, ?string $boolean, Audience $audience): array
    {
        $params = [];
        $match = $this->matchClause('title, content, location_name', 'title', $q, $boolean, $params);

        $ids = $this->em->getConnection()->fetchFirstColumn(
            sprintf("SELECT p.id FROM post p WHERE %s AND p.status = 'published' ORDER BY p.created_at DESC LIMIT %d", $match, self::POST_CANDIDATES),
            $params
        );

        $visible = $this->posts->findVisibleByIds(array_map(static fn(string $id) => Uuid::fromBinary($id), $ids), $audience);

        return array_slice($visible, 0, self::LIMIT);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function matchClause(string $fulltextColumns, string $prefixColumn, string $q, ?string $boolean, array &$params): string
    {
        if ($boolean !== null) {
            $params['boolean'] = $boolean;

            return sprintf('MATCH(%s) AGAINST(:boolean IN BOOLEAN MODE)', $fulltextColumns);
        }

        $params['prefix'] = addcslashes($q, '%_\\') . '%';

        return sprintf('%s LIKE :prefix', $prefixColumn);
    }

    /**
     * @param list<string> $binaryIds
     *
     * @return list<object>
     */
    private function hydrate(object $repository, array $binaryIds): array
    {
        return array_values(array_filter(array_map(
            static fn(string $id) => $repository->find(Uuid::fromBinary($id)),
            $binaryIds
        )));
    }

    /** @return list<array{name: string, slug: string, score: float}> */
    public function topTags(int $limit = 10): array
    {
        return array_map(
            static fn(Tag $tag) => ['name' => $tag->getName(), 'slug' => $tag->getSlug(), 'score' => $tag->getTrendingScore()],
            $this->tags->trending($limit)
        );
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<string>
     */
    private static function binary(array $ids): array
    {
        return array_map(static fn(Uuid $id) => $id->toBinary(), $ids);
    }
}
