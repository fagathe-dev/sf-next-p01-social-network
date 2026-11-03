<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Doctrine\ORM\EntityManagerInterface;
use Fagathe\CorePhp\Http\ResponseTrait;
use Fagathe\GatherCore\Dto\Input\ModerationDecisionInput;
use Fagathe\GatherCore\Dto\Input\ReportInput;
use Fagathe\GatherCore\Dto\Mail\ModerationNoticeMailDto;
use Fagathe\GatherCore\Entity\Comment;
use Fagathe\GatherCore\Entity\ModerationLog;
use Fagathe\GatherCore\Entity\Post;
use Fagathe\GatherCore\Entity\Report;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Auth\UserRole;
use Fagathe\GatherCore\Enum\Auth\UserStatus;
use Fagathe\GatherCore\Enum\Moderation\ModerationActionType;
use Fagathe\GatherCore\Enum\Moderation\ReportStatus;
use Fagathe\GatherCore\Enum\Moderation\ReportTargetType;
use Fagathe\GatherCore\Enum\Social\ContentStatus;
use Fagathe\GatherCore\Mailer\MailDispatcher;
use Fagathe\GatherCore\Repository\CommentRepository;
use Fagathe\GatherCore\Repository\PostRepository;
use Fagathe\GatherCore\Repository\ReportRepository;
use Fagathe\GatherCore\Repository\UserRepository;
use Fagathe\GatherCore\Security\AudienceResolver;
use Fagathe\GatherCore\Trait\JsonInputTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Signalements et sanctions : masquage préventif à 5 signalements distincts, sanctions graduées, journal.
 */
final class ModerationService
{
    use ResponseTrait, JsonInputTrait;

    public const AUTO_HIDE_THRESHOLD = 5;

    private const READ_ONLY_DAYS = [1, 3];
    private const SUSPENSION_DAYS = [7, 30];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReportRepository $reports,
        private readonly PostRepository $posts,
        private readonly CommentRepository $comments,
        private readonly UserRepository $users,
        private readonly AudienceResolver $audiences,
        private readonly MailDispatcher $mailDispatcher,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {}

    public function report(Request $request): object
    {
        $audience = $this->audiences->current();
        $input = $this->readInput($request->getContent(), ReportInput::class);
        if ($this->isErrorResponse($input, ReportInput::class)) {
            return $input;
        }

        $id = Uuid::fromString($input->targetId);
        $target = match ($input->targetType) {
            ReportTargetType::Post => $this->posts->find($id),
            ReportTargetType::Comment => $this->comments->find($id),
            ReportTargetType::User => $this->users->find($id),
        };

        $visible = match (true) {
            $target instanceof Post => $audience->canViewPost($target),
            $target instanceof Comment => $audience->canViewPost($target->getPost()),
            default => $target !== null,
        };
        if (!$visible) {
            return $this->sendJson(['message' => 'Contenu introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if ($target instanceof User && $audience->isSelf($target)) {
            return $this->sendViolations(['targetId' => 'Vous ne pouvez pas vous signaler vous-même.']);
        }

        // UNIQUE(reporter, target_type, target_id) : un second signalement ne compte pas deux fois
        $existing = $this->reports->findOneBy(['reporter' => $audience->user, 'targetType' => $input->targetType, 'targetId' => $id]);
        if ($existing !== null) {
            return $this->sendJson(['message' => 'Signalement déjà enregistré.']);
        }

        $report = new Report($audience->user, $target, $input->reason, $input->details ?: null);
        $this->em->persist($report);
        $this->em->flush();

        $this->incrementCounter($target);
        $this->applyAutoHide($report, $target);

        return $this->sendJson(['message' => 'Merci, notre équipe va examiner ce signalement.'], Response::HTTP_CREATED);
    }

    /**
     * Applique la décision du staff à la cible, clôt tous ses signalements ouverts et écrit dans le journal.
     *
     * @return string|null message d'erreur, null si la décision est appliquée
     */
    public function resolve(Report $report, User $moderator, ModerationDecisionInput $decision): ?string
    {
        $author = $this->authorOf($report);

        if ($author !== null && $author->getId()->equals($moderator->getId())) {
            return 'Vous ne pouvez pas traiter un signalement vous concernant.';
        }
        if ($this->isSanction($decision->action) && $author?->getRole() === UserRole::ROLE_ADMIN) {
            return 'Un administrateur ne peut pas être sanctionné depuis cette file.';
        }

        $error = $this->applyDecision($report, $author, $decision);
        if ($error !== null) {
            return $error;
        }

        foreach ($this->reports->openForTarget($report->getTargetType(), $report->getTargetId()) as $open) {
            $open->resolve($moderator);
        }

        $this->em->persist(new ModerationLog($moderator, $decision->action, $decision->reason, $author, $decision->durationDays));
        $this->em->flush();

        if ($author !== null && $decision->action !== ModerationActionType::Dismiss) {
            $this->notify($author, $decision);
        }

        return null;
    }

    private function applyDecision(Report $report, ?User $author, ModerationDecisionInput $decision): ?string
    {
        $content = $report->getReportedPost() ?? $report->getReportedComment();

        switch ($decision->action) {
            case ModerationActionType::Dismiss:
                $content?->setStatus(ContentStatus::Published);
                break;

            case ModerationActionType::DeleteContent:
                if ($content === null) {
                    return 'Ce signalement ne porte pas sur un contenu.';
                }
                $content->setStatus(ContentStatus::Deleted);
                break;

            case ModerationActionType::Warning:
                $author?->addWarning();
                break;

            case ModerationActionType::ReadOnly:
                return $this->applyTemporary($author, UserStatus::ReadOnly, $decision->durationDays, self::READ_ONLY_DAYS);

            case ModerationActionType::Suspension:
                return $this->applyTemporary($author, UserStatus::Suspended, $decision->durationDays, self::SUSPENSION_DAYS);

            case ModerationActionType::Ban:
                $author?->applySanction(UserStatus::Banned);
                break;
        }

        return null;
    }

    /**
     * @param array{0: int, 1: int} $bounds
     */
    private function applyTemporary(?User $author, UserStatus $status, ?int $days, array $bounds): ?string
    {
        if ($days === null || $days < $bounds[0] || $days > $bounds[1]) {
            return sprintf('La durée doit être comprise entre %d et %d jours.', $bounds[0], $bounds[1]);
        }

        $author?->applySanction($status, new \DateTimeImmutable(sprintf('+%d days', $days)));

        return null;
    }

    private function isSanction(ModerationActionType $action): bool
    {
        return in_array($action, [ModerationActionType::Warning, ModerationActionType::ReadOnly, ModerationActionType::Suspension, ModerationActionType::Ban], true);
    }

    private function authorOf(Report $report): ?User
    {
        return $report->getReportedPost()?->getAuthor()
            ?? $report->getReportedComment()?->getAuthor()
            ?? $report->getReportedUser();
    }

    private function incrementCounter(Post|Comment|User $target): void
    {
        if ($target instanceof Post) {
            $this->posts->adjustCounter($target, 'reports', 1);
        } elseif ($target instanceof Comment) {
            $target->incrementReportsCount();
            $this->em->flush();
        }
    }

    private function applyAutoHide(Report $report, Post|Comment|User $target): void
    {
        if ($target instanceof User) {
            return;
        }

        $distinct = $this->reports->countDistinctReporters($report->getTargetType(), $report->getTargetId());
        if ($distinct < self::AUTO_HIDE_THRESHOLD || $target->getStatus() !== ContentStatus::Published) {
            return;
        }

        $target->setStatus(ContentStatus::AutoHidden);
        foreach ($this->reports->openForTarget($report->getTargetType(), $report->getTargetId()) as $open) {
            $open->setStatus(ReportStatus::AutoHidden);
        }
        $this->em->flush();
    }

    private function notify(User $author, ModerationDecisionInput $decision): void
    {
        $this->mailDispatcher->dispatchSilently(new ModerationNoticeMailDto(
            recipientEmail: (string) $author->getEmail(),
            recipientName: (string) $author->getDisplayName(),
            action: $decision->action->value,
            reason: $decision->reason,
            durationDays: $decision->durationDays,
        ));
    }
}
