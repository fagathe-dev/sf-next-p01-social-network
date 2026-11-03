<?php

namespace Fagathe\GatherCore\Entity;

use Fagathe\GatherCore\Enum\Auth\UserRequestTypeEnum;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Fagathe\GatherCore\Repository\UserRequestRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRequestRepository::class)]
#[ORM\Table(name: 'user_requests')]
#[ORM\Index(name: 'idx_user_request_token', columns: ['token'])]
#[ORM\Index(name: 'idx_user_request_code', columns: ['code'])]
class UserRequest
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['user_request.read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /**
     * Code court pour la saisie manuelle (ex: OTP à 6 chiffres).
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $code = null;

    /**
     * Jeton long et sécurisé pour les liens cliquables (ex: réinitialisation de mot de passe).
     */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $token = null;

    #[ORM\Column(length: 40, enumType: UserRequestTypeEnum::class)]
    private UserRequestTypeEnum $type;

    /**
     * Données contextuelles facultatives (ex: nouvelle adresse e-mail en attente).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isUsed = false;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->isUsed = false;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code;
        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(?string $token): static
    {
        $this->token = $token;
        return $this;
    }

    public function getType(): UserRequestTypeEnum
    {
        return $this->type;
    }

    public function setType(UserRequestTypeEnum $type): static
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    public function setPayload(?array $payload): static
    {
        $this->payload = $payload;
        return $this;
    }

    public function isUsed(): bool
    {
        return $this->isUsed;
    }

    public function setIsUsed(bool $isUsed): static
    {
        $this->isUsed = $isUsed;
        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function isValid(): bool
    {
        return !$this->isUsed && $this->expiresAt > new \DateTimeImmutable();
    }
}
