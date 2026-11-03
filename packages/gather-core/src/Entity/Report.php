<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Fagathe\GatherCore\Enum\Moderation\ReportReason;
use Fagathe\GatherCore\Enum\Moderation\ReportStatus;
use Fagathe\GatherCore\Enum\Moderation\ReportTargetType;
use Fagathe\GatherCore\Repository\ReportRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ReportRepository::class)]
#[ORM\Table(name: 'report')]
#[ORM\UniqueConstraint(name: 'uniq_report_target', columns: ['reporter_id', 'target_type', 'target_id'])]
#[ORM\Index(name: 'idx_report_queue', columns: ['status', 'target_type', 'created_at'])]
class Report
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    // Identifiant de la cible quel que soit son type : porte la contrainte d'unicité
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $targetId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Post $reportedPost = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Comment $reportedComment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $reportedUser = null;

    #[ORM\Column(length: 20, enumType: ReportTargetType::class)]
    private ReportTargetType $targetType;

    #[ORM\Column(length: 20, enumType: ReportStatus::class)]
    private ReportStatus $status = ReportStatus::Pending;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $resolvedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $reporter,

        Post|Comment|User $target,

        #[ORM\Column(length: 30, enumType: ReportReason::class)]
        private ReportReason $reason,

        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $details = null,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->targetId = $target->getId();

        if ($target instanceof Post) {
            $this->reportedPost = $target;
            $this->targetType = ReportTargetType::Post;
        } elseif ($target instanceof Comment) {
            $this->reportedComment = $target;
            $this->targetType = ReportTargetType::Comment;
        } else {
            $this->reportedUser = $target;
            $this->targetType = ReportTargetType::User;
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getReporter(): User
    {
        return $this->reporter;
    }

    public function getTargetType(): ReportTargetType
    {
        return $this->targetType;
    }

    public function getTargetId(): Uuid
    {
        return $this->targetId;
    }

    public function getReportedPost(): ?Post
    {
        return $this->reportedPost;
    }

    public function getReportedComment(): ?Comment
    {
        return $this->reportedComment;
    }

    public function getReportedUser(): ?User
    {
        return $this->reportedUser;
    }

    public function getReason(): ReportReason
    {
        return $this->reason;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function getStatus(): ReportStatus
    {
        return $this->status;
    }

    public function setStatus(ReportStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function resolve(User $moderator): static
    {
        $this->status = ReportStatus::Resolved;
        $this->resolvedBy = $moderator;
        $this->resolvedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getResolvedBy(): ?User
    {
        return $this->resolvedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }
}
