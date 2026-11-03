<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Fagathe\GatherCore\Enum\Auth\UserRole;
use Fagathe\GatherCore\Enum\Auth\UserStatus;
use Fagathe\GatherCore\Repository\UserRepository;
use Fagathe\GatherCore\Trait\ConfigurableEntityTrait;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_USERNAME', fields: ['username'])]
#[ORM\Index(name: 'idx_user_search', columns: ['username', 'firstname', 'lastname', 'bio'], flags: ['fulltext'])]
#[ORM\Index(name: 'idx_user_privacy', columns: ['is_private', 'status'])]
#[UniqueEntity(fields: ['email'], message: 'Cette adresse email est déjà utilisée.')]
#[UniqueEntity(fields: ['username'], message: 'Ce nom d\'utilisateur est déjà pris.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** Âge minimum pour utiliser le service */
    public const MINIMUM_AGE = 14;

    use ConfigurableEntityTrait;

    public const DEFAULT_SETTINGS = [
        'bio' => null,
        'website' => null,
        'email_notifications' => true,
    ];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['user.read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\Email(message: 'Cette adresse e-mail est invalide !')]
    #[Assert\NotBlank(message: 'L\'adresse e-mail est obligatoire')]
    #[Groups(['user.read'])]
    private ?string $email = null;

    #[ORM\Column(length: 50, enumType: UserRole::class)]
    #[Groups(['user.read'])]
    private UserRole $role = UserRole::ROLE_USER;

    #[ORM\Column]
    #[Assert\Regex(
        pattern: '#^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}$#',
        message: 'Le mot de passe doit contenir au moins une majuscule, une minuscule et un chiffre avec au moins 8 caractères !'
    )]
    #[Assert\NotBlank(message: 'Ce champ est obligatoire !')]
    private ?string $password = null;

    #[ORM\Column(length: 90)]
    #[Assert\NotBlank(message: 'Le nom d\'utilisateur est obligatoire')]
    #[Assert\Length(min: 3, max: 90)]
    #[Groups(['user.read'])]
    private ?string $username = null;

    #[ORM\Column(length: 80, nullable: true)]
    #[Groups(['user.read'])]
    private ?string $firstname = null;

    #[ORM\Column(length: 80, nullable: true)]
    #[Groups(['user.read'])]
    private ?string $lastname = null;

    // URL complète (upload : AttachmentFileService, ou image externe)
    #[ORM\Column(length: 1024, nullable: true)]
    #[Groups(['user.read'])]
    private ?string $avatar = null;

    #[ORM\Column(length: 1024, nullable: true)]
    #[Groups(['user.read'])]
    private ?string $banner = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['user.read'])]
    private ?string $bio = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Groups(['user.read'])]
    private ?\DateTimeImmutable $birthDate = null;

    // Renseignée tant que le compte est désactivé par son propriétaire : invisible, réactivé à la connexion
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    #[ORM\Column]
    #[Groups(['user.read'])]
    private bool $isPrivate = false;

    // Indépendant du compte privé : un compte privé peut rester trouvable, avec un profil restreint
    #[ORM\Column]
    #[Groups(['user.read'])]
    private bool $isExcludedFromSearch = false;

    #[ORM\Column(length: 20, enumType: UserStatus::class)]
    #[Groups(['user.read'])]
    private UserStatus $status = UserStatus::Active;

    #[ORM\Column(nullable: true)]
    #[Groups(['user.read'])]
    private ?\DateTimeImmutable $sanctionExpiresAt = null;

    #[ORM\Column]
    private int $warningsCount = 0;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $invitedBy = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['user.read'])]
    private ?bool $isVerified = false;

    #[ORM\Column(nullable: true)]
    #[Groups(['user.read'])]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column]
    #[Groups(['user.read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['user.read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    #[Groups(['user.read'])]
    private ?string $displayName = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->settings = static::getDefaultSettings();
    }

    /**
     * @return array<string, mixed>
     */
    protected static function getDefaultSettings(): array
    {
        return self::DEFAULT_SETTINGS;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function setRole(UserRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getRoles(): array
    {
        return array_unique([$this->role->value]);
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0" . self::class . "\0password"] = hash('crc32c', (string) $this->password);

        return $data;
    }

    #[\Deprecated]
    public function eraseCredentials(): void {}

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(?string $firstname): static
    {
        $this->firstname = $firstname;

        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(?string $lastname): static
    {
        $this->lastname = $lastname;

        return $this;
    }

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function setAvatar(?string $avatar): static
    {
        $this->avatar = $avatar;

        return $this;
    }

    public function getBanner(): ?string
    {
        return $this->banner;
    }

    public function setBanner(?string $banner): static
    {
        $this->banner = $banner;

        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): static
    {
        $this->bio = $bio;

        return $this;
    }

    public function getBirthDate(): ?\DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function setBirthDate(?\DateTimeImmutable $birthDate): static
    {
        $this->birthDate = $birthDate;

        return $this;
    }

    public function isPrivate(): bool
    {
        return $this->isPrivate;
    }

    public function setIsPrivate(bool $isPrivate): static
    {
        $this->isPrivate = $isPrivate;

        return $this;
    }

    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    /**
     * Statut réellement applicable : une sanction temporaire expirée n'a plus d'effet,
     * même si aucune tâche n'a encore remis la colonne à jour.
     */
    public function isExcludedFromSearch(): bool
    {
        return $this->isExcludedFromSearch;
    }

    public function setIsExcludedFromSearch(bool $isExcludedFromSearch): static
    {
        $this->isExcludedFromSearch = $isExcludedFromSearch;

        return $this;
    }

    public function isDeactivated(): bool
    {
        return $this->deactivatedAt !== null;
    }

    public function deactivate(): static
    {
        $this->deactivatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function reactivate(): static
    {
        $this->deactivatedAt = null;

        return $this;
    }

    /** Un compte banni ou désactivé n'existe plus pour les autres membres. */
    public function isVisible(): bool
    {
        return !$this->isDeactivated() && $this->getEffectiveStatus() !== UserStatus::Banned;
    }

    public static function isOldEnough(\DateTimeImmutable $birthDate): bool
    {
        $today = new \DateTimeImmutable('today');

        return $birthDate <= $today && $birthDate->diff($today)->y >= self::MINIMUM_AGE;
    }

    public function getEffectiveStatus(): UserStatus
    {
        $isTemporary = $this->status === UserStatus::ReadOnly || $this->status === UserStatus::Suspended;
        if ($isTemporary && $this->sanctionExpiresAt !== null && $this->sanctionExpiresAt <= new \DateTimeImmutable()) {
            return UserStatus::Active;
        }

        return $this->status;
    }

    public function applySanction(UserStatus $status, ?\DateTimeImmutable $expiresAt = null): static
    {
        $this->status = $status;
        $this->sanctionExpiresAt = $expiresAt;

        return $this;
    }

    public function getSanctionExpiresAt(): ?\DateTimeImmutable
    {
        return $this->sanctionExpiresAt;
    }

    public function getWarningsCount(): int
    {
        return $this->warningsCount;
    }

    public function addWarning(): static
    {
        ++$this->warningsCount;

        return $this;
    }

    public function getInvitedBy(): ?User
    {
        return $this->invitedBy;
    }

    public function setInvitedBy(?User $invitedBy): static
    {
        $this->invitedBy = $invitedBy;

        return $this;
    }

    public function isVerified(): ?bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(?bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function setEmailVerifiedAt(?\DateTimeImmutable $emailVerifiedAt): static
    {
        $this->emailVerifiedAt = $emailVerifiedAt;

        return $this;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
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

    public function getDisplayName(): ?string
    {
        if (!empty($this->firstname) && !empty($this->lastname)) {
            return ucfirst($this->firstname) . ' ' . strtoupper($this->lastname);
        }

        if (!empty($this->username)) {
            return '@' . ($this->username ?? 'utilisateur');
        }

        return null;
    }

    public function setDisplayName(): static
    {
        $this->displayName = $this->getDisplayName();

        return $this;
    }
}
