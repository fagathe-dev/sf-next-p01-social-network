<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Doctrine\ORM\EntityManagerInterface;
use Fagathe\CorePhp\Http\ResponseTrait;
use Fagathe\GatherCore\Dto\Input\CreateGroupInput;
use Fagathe\GatherCore\Dto\Mail\GroupInvitationMailDto;
use Fagathe\GatherCore\Dto\Output\GroupCardOutput;
use Fagathe\GatherCore\Dto\Output\UserSummaryOutput;
use Fagathe\GatherCore\Entity\GroupMember;
use Fagathe\GatherCore\Entity\SocialGroup;
use Fagathe\GatherCore\Enum\Social\GroupMemberStatus;
use Fagathe\GatherCore\Enum\Social\GroupRole;
use Fagathe\GatherCore\Enum\Social\NotificationType;
use Fagathe\GatherCore\Generator\AuthLinkGenerator;
use Fagathe\GatherCore\Mailer\MailDispatcher;
use Fagathe\GatherCore\Repository\GroupMemberRepository;
use Fagathe\GatherCore\Repository\PostRepository;
use Fagathe\GatherCore\Repository\SocialGroupRepository;
use Fagathe\GatherCore\Repository\UserRepository;
use Fagathe\GatherCore\Security\Audience;
use Fagathe\GatherCore\Security\AudienceResolver;
use Fagathe\GatherCore\Trait\JsonInputTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class GroupService
{
    use ResponseTrait, JsonInputTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SocialGroupRepository $groups,
        private readonly GroupMemberRepository $members,
        private readonly PostRepository $posts,
        private readonly UserRepository $users,
        private readonly AudienceResolver $audiences,
        private readonly PostService $postService,
        private readonly ContentParser $parser,
        private readonly MailDispatcher $mailDispatcher,
        private readonly AuthLinkGenerator $links,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly NotificationService $notifier,
    ) {}

    public function list(int $page, bool $onlyMine): object
    {
        $result = $this->groups->listVisible($this->audiences->current(), $page, $onlyMine);

        return $this->sendJson([
            'items' => array_map(GroupCardOutput::from(...), $result['items']),
            'page' => $result['page'],
            'hasMore' => $result['hasMore'],
        ]);
    }

    /**
     * Un groupe privé reste trouvable par lien pour pouvoir demander à le rejoindre,
     * mais sa page n'expose alors que sa carte : ni posts, ni membres.
     */
    public function show(string $slug): object
    {
        $group = $this->groups->findOneBy(['slug' => $slug]);
        if ($group === null) {
            return $this->notFound();
        }

        return $this->sendJson($this->details($group, $this->audiences->current()));
    }

    public function posts(string $slug, int $page): object
    {
        $audience = $this->audiences->current();
        $group = $this->groups->findOneBy(['slug' => $slug]);
        if ($group === null || !$audience->canSeeGroup($group)) {
            return $this->notFound();
        }

        return $this->sendJson($this->postService->page($this->posts->byGroup($group, $audience, $page), $audience));
    }

    public function create(Request $request): object
    {
        $audience = $this->audiences->current();
        if (!$audience->canWrite()) {
            return $this->sendJson(['message' => 'Votre compte ne peut pas créer de communauté pour le moment.'], Response::HTTP_FORBIDDEN);
        }

        $input = $this->readInput($request->getContent(), CreateGroupInput::class);
        if ($this->isErrorResponse($input, CreateGroupInput::class)) {
            return $input;
        }

        $group = new SocialGroup(trim($input->name), $this->uniqueSlug($input->name), $audience->user);
        $group->setDescription($input->description ?: null)
            ->setThemePalette($input->themePalette)
            ->setIconName($input->iconName ?: null)
            ->setVisibility($input->visibility)
            ->setMembersCount(1);

        $this->em->persist($group);
        $this->em->persist(new GroupMember($group, $audience->user, GroupRole::Owner));
        $this->em->flush();

        return $this->sendJson($this->details($group, $this->audiences->for($audience->user)), Response::HTTP_CREATED);
    }

    /** Groupe public : adhésion immédiate. Groupe privé : demande à valider. */
    public function join(string $slug): object
    {
        $audience = $this->audiences->current();
        $group = $this->groups->findOneBy(['slug' => $slug]);
        if ($group === null) {
            return $this->notFound();
        }
        if (!$audience->canWrite()) {
            return $this->sendJson(['message' => 'Votre compte ne peut pas rejoindre de communauté pour le moment.'], Response::HTTP_FORBIDDEN);
        }

        if ($this->members->findMembership($group, $audience->user) === null) {
            $status = $group->isPublic() ? GroupMemberStatus::Joined : GroupMemberStatus::Pending;
            $this->em->persist(new GroupMember($group, $audience->user, GroupRole::Member, $status));
            $this->em->flush();
            $this->refreshCount($group);
        }

        return $this->sendJson($this->details($group, $this->audiences->for($audience->user)));
    }

    public function leave(string $slug): object
    {
        $audience = $this->audiences->current();
        $group = $this->groups->findOneBy(['slug' => $slug]);
        $membership = $group !== null ? $this->members->findMembership($group, $audience->user) : null;
        if ($membership === null) {
            return $this->notFound();
        }
        if ($membership->getRole() === GroupRole::Owner) {
            return $this->sendJson(['message' => 'Le propriétaire ne peut pas quitter sa communauté.'], Response::HTTP_CONFLICT);
        }

        $this->em->remove($membership);
        $this->em->flush();
        $this->refreshCount($group);

        return $this->sendJson($this->details($group, $this->audiences->for($audience->user)));
    }

    public function members(string $slug): object
    {
        $audience = $this->audiences->current();
        $group = $this->groups->findOneBy(['slug' => $slug]);
        if ($group === null || !$audience->canSeeGroup($group)) {
            return $this->notFound();
        }

        $mine = $audience->user !== null ? $this->members->findMembership($group, $audience->user) : null;
        $pending = $mine?->canManage() ? $this->members->listByStatus($group, GroupMemberStatus::Pending) : [];

        $present = static fn(GroupMember $member) => [
            'user' => UserSummaryOutput::from($member->getUser()),
            'role' => $member->getRole()->value,
        ];

        return $this->sendJson([
            'members' => array_map($present, $this->members->listByStatus($group, GroupMemberStatus::Joined)),
            'pending' => array_map($present, $pending),
        ]);
    }

    /** Valide ou refuse une demande d'adhésion : réservé au propriétaire et aux modérateurs du groupe. */
    public function decide(string $slug, string $username, bool $accept): object
    {
        $audience = $this->audiences->current();
        $group = $this->groups->findOneBy(['slug' => $slug]);
        if ($group === null) {
            return $this->notFound();
        }
        if (!$this->members->findMembership($group, $audience->user)?->canManage()) {
            return $this->sendJson(['message' => 'Action réservée aux responsables de la communauté.'], Response::HTTP_FORBIDDEN);
        }

        $candidate = $this->users->findOneBy(['username' => $username]);
        $membership = $candidate !== null ? $this->members->findMembership($group, $candidate) : null;
        if ($membership === null || $membership->isJoined()) {
            return $this->notFound();
        }

        $accept ? $membership->approve() : $this->em->remove($membership);
        $this->em->flush();
        $this->refreshCount($group);
        if ($accept) {
            $this->notifier->notify($candidate, $audience->user, NotificationType::GroupJoinApproved, $group);
        }

        return $this->sendJson(null, Response::HTTP_NO_CONTENT);
    }

    /** Invitation par e-mail : l'adhésion à un groupe privé reste validée par ses responsables. */
    public function invite(string $slug, Request $request): object
    {
        $audience = $this->audiences->current();
        $group = $this->groups->findOneBy(['slug' => $slug]);
        if ($group === null || !$audience->isMemberOf($group)) {
            return $this->notFound();
        }

        $data = json_decode($request->getContent(), true);
        $email = is_array($data) && is_string($data['email'] ?? null) ? trim($data['email']) : '';
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->sendViolations(['email' => 'Adresse e-mail invalide.']);
        }

        // Membre déjà inscrit avec cette adresse : il est aussi prévenu dans l'application
        $invitee = $this->users->findOneBy(['email' => mb_strtolower($email)]);
        if ($invitee !== null && $this->members->findMembership($group, $invitee) === null) {
            $this->notifier->notify($invitee, $audience->user, NotificationType::GroupInvitation, $group);
        }

        $this->mailDispatcher->dispatchSilently(new GroupInvitationMailDto(
            recipientEmail: $email,
            recipientName: $email,
            inviterName: (string) $audience->user->getDisplayName(),
            groupName: $group->getName(),
            groupUrl: $this->links->groupUrl($group->getSlug()),
        ));

        return $this->sendJson(['message' => 'Invitation envoyée.']);
    }

    /** Membres connus : pour le sélecteur de cible de publication. */
    private function details(SocialGroup $group, Audience $audience): array
    {
        $membership = $audience->user !== null ? $this->members->findMembership($group, $audience->user) : null;

        return [
            'group' => GroupCardOutput::from($group),
            'canSeeContent' => $audience->canSeeGroup($group),
            'owner' => UserSummaryOutput::from($group->getOwner()),
            'membership' => $membership?->getStatus()->value,
            'role' => $membership?->getRole()->value,
            'canPost' => $membership?->isJoined() === true && $audience->canWrite(),
            'canManage' => $membership?->canManage() === true,
        ];
    }

    private function refreshCount(SocialGroup $group): void
    {
        $group->setMembersCount($this->members->countJoined($group));
        $this->em->flush();
    }

    private function uniqueSlug(string $name): string
    {
        $base = $this->parser->slug($name, fallback: 'communaute');
        $slug = $base;
        $suffix = 2;
        while ($this->groups->findOneBy(['slug' => $slug]) !== null) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }

    private function notFound(): object
    {
        return $this->sendJson(['message' => 'Communauté introuvable.'], Response::HTTP_NOT_FOUND);
    }
}
