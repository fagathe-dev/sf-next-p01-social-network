<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Fagathe\GatherCore\Dto\Input\SearchQueryInput;
use Fagathe\GatherCore\Dto\Output\GroupCardOutput;
use Fagathe\GatherCore\Dto\Output\LinkPreviewOutput;
use Fagathe\GatherCore\Entity\Post;
use Fagathe\GatherCore\Entity\SocialGroup;
use Fagathe\GatherCore\Entity\Tag;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Auth\UserStatus;
use Fagathe\GatherCore\Enum\Social\GroupVisibility;
use Fagathe\GatherCore\Repository\PostRepository;
use Fagathe\GatherCore\Repository\TagRepository;
use Fagathe\GatherCore\Security\AudienceResolver;
use Fagathe\GatherCore\Service\LinkPreviewException;
use Fagathe\GatherCore\Service\LinkPreviewExtractorService;
use Fagathe\GatherCore\Service\PostService;
use Fagathe\GatherCore\Service\SearchService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api', name: 'api_')]
final class DiscoveryController extends ApiController
{
    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(
        SearchService $search,
        AudienceResolver $audiences,
        ValidatorInterface $validator,
        #[MapQueryParameter] string $q = '',
        #[MapQueryParameter] string $type = 'all',
    ): JsonResponse {
        $input = new SearchQueryInput($q, $type);
        if (count($validator->validate($input)) > 0) {
            return $this->json(['message' => 'Recherche invalide.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($search->search($input, $audiences->current()));
    }

    #[Route('/tags/{slug}/posts', name: 'tag_posts', methods: ['GET'])]
    public function tagPosts(
        string $slug,
        TagRepository $tags,
        PostRepository $posts,
        PostService $postService,
        AudienceResolver $audiences,
        #[MapQueryParameter] int $page = 1,
    ): JsonResponse {
        $tag = $tags->findOneBy(['slug' => $slug]);
        if ($tag === null) {
            return $this->json(['message' => 'Tag introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $audience = $audiences->current();

        return $this->json(['tag' => ['name' => $tag->getName(), 'slug' => $tag->getSlug()]] + $postService->page($posts->byTag($tag, $audience, $page), $audience));
    }

    #[Route('/links/preview', name: 'links_preview', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function linkPreview(Request $request, LinkPreviewExtractorService $extractor): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $url = is_array($data) && is_string($data['url'] ?? null) ? $data['url'] : '';

        try {
            return $this->json(LinkPreviewOutput::from($extractor->extract($url)));
        } catch (LinkPreviewException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /** Contenus indexables : les comptes privés et groupes privés n'y figurent jamais. */
    #[Route('/sitemap', name: 'sitemap', methods: ['GET'])]
    public function sitemap(EntityManagerInterface $em, PostRepository $posts): JsonResponse
    {
        return $this->json([
            'posts' => array_map(
                static fn(Post $post) => ['path' => sprintf('/post/%s-%s', $post->getSlug(), $post->getId()), 'updatedAt' => $post->getUpdatedAt() ?? $post->getCreatedAt()],
                $posts->publicForSitemap()
            ),
            'groups' => array_map(
                static fn(SocialGroup $group) => ['path' => '/g/' . $group->getSlug()],
                $em->getRepository(SocialGroup::class)->findBy(['visibility' => GroupVisibility::Public], limit: 5000)
            ),
            'tags' => array_map(
                static fn(Tag $tag) => ['path' => '/tag/' . $tag->getSlug()],
                $em->getRepository(Tag::class)->findBy([], ['trendingScore' => 'DESC'], 1000)
            ),
            'users' => array_map(
                static fn(User $user) => ['path' => '/u/' . $user->getUsername()],
                $em->getRepository(User::class)->findBy(['isPrivate' => false, 'isExcludedFromSearch' => false, 'deactivatedAt' => null, 'status' => UserStatus::Active, 'isVerified' => true], limit: 5000)
            ),
        ]);
    }

    /** Colonne droite : top tags et communautés à découvrir. */
    #[Route('/discover', name: 'discover', methods: ['GET'])]
    public function discover(SearchService $search, EntityManagerInterface $em): JsonResponse
    {
        return $this->json([
            'tags' => $search->topTags(),
            'groups' => array_map(
                GroupCardOutput::from(...),
                $em->getRepository(SocialGroup::class)->findBy(['visibility' => GroupVisibility::Public], ['membersCount' => 'DESC'], 6)
            ),
        ]);
    }
}
