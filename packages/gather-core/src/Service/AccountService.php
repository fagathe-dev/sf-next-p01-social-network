<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Fagathe\GatherCore\Dto\Account\ChangeEmailDto;
use Fagathe\GatherCore\Dto\Account\ChangePasswordDto;
use Fagathe\GatherCore\Dto\Account\DeleteAccountDto;
use Fagathe\GatherCore\Dto\Account\UpdateAccountDto;
use Fagathe\GatherCore\Dto\Account\UploadAvatarDto;
use Fagathe\GatherCore\Dto\Mail\AccountDeletionCodeMailDto;
use Fagathe\GatherCore\Dto\Mail\EmailChangeVerificationMailDto;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Auth\UserRequestTypeEnum;
use Fagathe\GatherCore\Enum\Auth\UserRole;
use Fagathe\GatherCore\Generator\AuthLinkGenerator;
use Fagathe\GatherCore\Mailer\MailDispatcher;
use Fagathe\GatherCore\Repository\UserRepository;
use Fagathe\CorePhp\Enum\LoggerLevelEnum;
use Fagathe\CorePhp\Http\ResponseTrait;
use Fagathe\CorePhp\Mailer\Exception\MailerExceptionInterface;
use Fagathe\CorePhp\Mailer\Exception\RecipientUnreachableException;
use Fagathe\CorePhp\Trait\DatetimeTrait;
use Fagathe\CorePhp\Trait\LoggerTrait;
use Fagathe\CorePhp\Uploader\FileUploadException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

final class AccountService
{
    use ResponseTrait, DatetimeTrait, LoggerTrait;

    /** Validité du lien de confirmation d'un changement d'e-mail */
    public const EMAIL_CHANGE_TTL_HOURS = 24;

    /** Suppression de compte : validité du code, essais autorisés, délai entre deux envois */
    public const DELETION_CODE_TTL_MINUTES = 10;
    public const DELETION_CODE_MAX_ATTEMPTS = 5;
    public const DELETION_CODE_RESEND_DELAY_SECONDS = 30;

    public function __construct(
        private readonly Security $security,
        private readonly UserRepository $userRepository,
        private readonly ValidatorInterface $validator,
        private readonly SerializerInterface $serializer,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AttachmentFileService $attachmentService,
        private readonly UserRequestService $userRequestService,
        private readonly MailDispatcher $mailDispatcher,
        private readonly CacheItemPoolInterface $cache,
        private readonly SecretDecoder $secretDecoder,
        private readonly AuthLinkGenerator $linkGenerator,
        private readonly AccountDeletionService $accountDeletion,
    ) {}

    public function getProfile(): object
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->sendJson(['message' => 'Utilisateur non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->sendJson($user, Response::HTTP_OK, context: ['groups' => ['user.read']]);
    }

    public function updateProfile(Request $request): object
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->sendJson(['message' => 'Utilisateur non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            /** @var UpdateAccountDto $dto */
            $dto = $this->serializer->deserialize($request->getContent(), UpdateAccountDto::class, 'json');
        } catch (Throwable) {
            return $this->sendJson(['message' => 'Format de données JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            return $this->sendViolations($violations);
        }

        $cleanUsername = trim($dto->username);
        if ($cleanUsername !== $user->getUsername()) {
            $existing = $this->userRepository->findOneBy(['username' => $cleanUsername]);
            if ($existing && (string) $existing->getId() !== (string) $user->getId()) {
                return $this->sendViolations(['username' => 'Ce nom d\'utilisateur est déjà utilisé.']);
            }
            $user->setUsername($cleanUsername);
        }

        $user->setFirstname($dto->firstname !== null && trim($dto->firstname) !== '' ? trim($dto->firstname) : null);
        $user->setLastname($dto->lastname !== null && trim($dto->lastname) !== '' ? trim($dto->lastname) : null);
        $user->setBio($dto->bio !== null && trim($dto->bio) !== '' ? trim($dto->bio) : null);
        // Laisser le champ vide ne change rien : une date déclarée ne peut pas être retirée
        if ($dto->birthDate !== null && $dto->birthDate !== '') {
            $birthDate = new \DateTimeImmutable($dto->birthDate);
            if (!User::isOldEnough($birthDate)) {
                return $this->sendViolations(['birthDate' => sprintf('Vous devez avoir au moins %d ans pour utiliser Gather.', User::MINIMUM_AGE)]);
            }
            $user->setBirthDate($birthDate);
        }
        $user->setUpdatedAt($this->now());

        try {
            $this->userRepository->save($user, flush: true, isCreation: false);

            return $this->sendJson($user, Response::HTTP_OK, context: ['groups' => ['user.read']]);
        } catch (Throwable $e) {
            $this->generateLog(
                LoggerLevelEnum::Critical,
                [
                    'message'   => 'Erreur critique lors de la mise à jour du profil',
                    'data'      => ['user_id' => (string) $user->getId()],
                    'exception' => $e->getMessage(),
                ],
                ['action' => 'account.update.critical_error']
            );

            return $this->sendJson(
                ['message' => 'Erreur interne lors de la mise à jour du profil.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Étape 1 : ne modifie PAS l'e-mail du compte. Crée une demande (24h) et envoie le lien à la nouvelle adresse.
     */
    public function changeEmail(Request $request): object
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->sendJson(['message' => 'Utilisateur non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            /** @var ChangeEmailDto $dto */
            $dto = $this->serializer->deserialize($request->getContent(), ChangeEmailDto::class, 'json');
        } catch (Throwable) {
            return $this->sendJson(['message' => 'Format de données JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            return $this->sendViolations($violations);
        }

        $cleanEmail = strtolower(trim($dto->email));
        if ($cleanEmail === strtolower((string) $user->getEmail())) {
            return $this->sendViolations(['email' => 'Cette adresse e-mail est déjà votre adresse actuelle.']);
        }

        $existing = $this->userRepository->findOneBy(['email' => $cleanEmail]);
        if ($existing && (string) $existing->getId() !== (string) $user->getId()) {
            return $this->sendViolations(['email' => 'Cette adresse e-mail est déjà utilisée.']);
        }

        $userRequest = $this->userRequestService->createRequest(
            user: $user,
            type: UserRequestTypeEnum::EmailChange,
            ttlMinutes: self::EMAIL_CHANGE_TTL_HOURS * 60,
            isOtp: false,
            payload: ['new_email' => $cleanEmail]
        );

        if ($userRequest?->getToken() === null) {
            return $this->sendJson(
                ['message' => 'Erreur lors de la génération de la demande de changement d\'e-mail.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        try {
            $this->mailDispatcher->dispatch(new EmailChangeVerificationMailDto(
                newEmail: $cleanEmail,
                recipientName: $this->resolveRecipientName($user),
                confirmationUrl: $this->linkGenerator->confirmEmailChangeUrl($userRequest->getToken()),
                ttlHours: self::EMAIL_CHANGE_TTL_HOURS,
            ));
        } catch (RecipientUnreachableException) {
            return $this->sendJson(
                ['message' => 'Le domaine de cette nouvelle adresse e-mail ne semble pas pouvoir recevoir d\'e-mails.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        } catch (MailerExceptionInterface) {
            // Déjà journalisé par AbstractMailerHandler
            return $this->sendJson(
                ['message' => 'Impossible d\'envoyer l\'e-mail de confirmation pour le moment.'],
                Response::HTTP_BAD_GATEWAY
            );
        } catch (Throwable $e) {
            $this->generateLog(
                LoggerLevelEnum::Critical,
                [
                    'message'   => 'Erreur critique lors de l\'envoi du lien de changement d\'e-mail',
                    'data'      => ['user_id' => (string) $user->getId()],
                    'exception' => $e,
                ],
                ['action' => 'account.email_change.critical_error']
            );

            return $this->sendJson(
                ['message' => 'Erreur interne lors de l\'envoi de l\'e-mail de confirmation.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $this->sendJson([
            'message' => sprintf(
                'Un lien de validation valable %dh a été envoyé à l\'adresse %s. Votre adresse actuelle reste active tant que vous n\'avez pas cliqué sur ce lien.',
                self::EMAIL_CHANGE_TTL_HOURS,
                $cleanEmail
            ),
        ]);
    }

    /**
     * Étape 2 (route publique, le jeton fait foi) : valide le lien et enregistre la nouvelle adresse.
     */
    public function confirmEmailChange(Request $request): object
    {
        $data = json_decode($request->getContent(), true);
        $token = trim((string) (is_array($data) ? ($data['token'] ?? '') : '') ?: $request->query->get('token', ''));

        if ($token === '') {
            return $this->sendJson(['message' => 'Le jeton de confirmation est obligatoire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $userRequest = $this->userRequestService->findValidRequest($token, UserRequestTypeEnum::EmailChange);
        if (!$userRequest) {
            return $this->sendJson(
                ['message' => 'Le lien de confirmation est invalide ou a expiré.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $newEmail = strtolower(trim((string) ($userRequest->getPayload()['new_email'] ?? '')));
        if ($newEmail === '') {
            return $this->sendJson(['message' => 'Données de changement d\'e-mail introuvables.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $userRequest->getUser();

        // Vérification finale d'unicité au cas où l'adresse aurait été prise entre-temps
        $existing = $this->userRepository->findOneBy(['email' => $newEmail]);
        if ($existing && (string) $existing->getId() !== (string) $user->getId()) {
            $this->userRequestService->consume($userRequest);

            return $this->sendJson(
                ['message' => 'Cette adresse e-mail est désormais utilisée par un autre compte.'],
                Response::HTTP_CONFLICT
            );
        }

        // Le clic sur le lien reçu prouve la possession de la nouvelle adresse
        $now = $this->now();
        $user->setEmail($newEmail)->setEmailVerifiedAt($now)->setUpdatedAt($now);

        // Consomme la demande et enregistre la nouvelle adresse
        $this->userRequestService->consume($userRequest);

        $this->generateLog(
            LoggerLevelEnum::Info,
            ['message' => 'Adresse e-mail modifiée', 'data' => ['user_id' => (string) $user->getId()]],
            ['action' => 'account.email_change.success']
        );

        return $this->sendJson(
            [
                'user'    => $user,
                'message' => 'Votre nouvelle adresse e-mail a été validée et enregistrée avec succès.',
            ],
            Response::HTTP_OK,
            context: ['groups' => ['user.read']]
        );
    }

    /**
     * Les deux mots de passe arrivent en base64 : ils sont décodés AVANT la désérialisation et la validation.
     */
    public function changePassword(Request $request): object
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->sendJson(['message' => 'Utilisateur non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        $body = $this->secretDecoder->decodeJsonBody($request->getContent(), ['currentPassword', 'newPassword']);
        if ($body === null) {
            return $this->sendJson(['message' => 'Format de données invalide.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            /** @var ChangePasswordDto $dto */
            $dto = $this->serializer->deserialize($body, ChangePasswordDto::class, 'json');
        } catch (Throwable) {
            return $this->sendJson(['message' => 'Format de données JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            return $this->sendViolations($violations);
        }

        if (!$this->hasher->isPasswordValid($user, $dto->currentPassword)) {
            return $this->sendJson(
                ['message' => 'Le mot de passe actuel est incorrect.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $user->setPassword($this->hasher->hashPassword($user, $dto->newPassword));
        $user->setUpdatedAt($this->now());
        $this->userRepository->save($user, flush: true, isCreation: false);

        return $this->sendJson(['message' => 'Mot de passe modifié avec succès.']);
    }

    public function updateAvatar(Request $request): object
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->sendJson(['message' => 'Utilisateur non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        $uploadedFile = $request->files->get('avatar') ?? $request->files->get('file');
        $dto = new UploadAvatarDto($uploadedFile);

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            return $this->sendViolations($violations);
        }

        try {
            // URL complète enregistrée dès l'upload (gather_core.default_uri)
            $this->attachmentService->uploadAvatar($dto->file, $user);

            $this->userRepository->save($user, flush: true, isCreation: false);

            return $this->sendJson($user, Response::HTTP_OK, context: ['groups' => ['user.read']]);
        } catch (FileUploadException $e) {
            return $this->sendJson(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            $this->generateLog(
                LoggerLevelEnum::Critical,
                [
                    'message'   => 'Erreur critique lors de l\'upload de l\'avatar',
                    'data'      => ['user_id' => (string) $user->getId()],
                    'exception' => $e->getMessage(),
                ],
                ['action' => 'account.avatar.update.error']
            );

            return $this->sendJson(
                ['message' => 'Erreur interne lors de la mise à jour de l\'avatar.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * POST /api/me/delete-request : envoie un code (OTP 10 min) confirmant la suppression du compte.
     * Un nouvel envoi n'est possible qu'après 30 secondes.
     */
    public function requestAccountDeletion(): object
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->sendJson(['message' => 'Utilisateur non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($user->getRole() !== UserRole::ROLE_USER) {
            return $this->staffDeletionRefused();
        }

        $sentItem = $this->cache->getItem('gather_account_deletion_code_sent_' . $user->getId());
        if ($sentItem->isHit()) {
            return $this->sendJson(
                ['message' => sprintf('Un code vient déjà de vous être envoyé. Patientez %d secondes avant d\'en demander un nouveau.', self::DELETION_CODE_RESEND_DELAY_SECONDS)],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        $deletionRequest = $this->userRequestService->createRequest(
            user: $user,
            type: UserRequestTypeEnum::AccountDeletion,
            ttlMinutes: self::DELETION_CODE_TTL_MINUTES,
            isOtp: true
        );

        if ($deletionRequest?->getCode() === null) {
            return $this->sendJson(
                ['message' => 'Erreur lors de la génération du code de confirmation.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        try {
            $this->mailDispatcher->dispatch(new AccountDeletionCodeMailDto(
                recipientEmail: (string) $user->getEmail(),
                recipientName: $this->resolveRecipientName($user),
                code: $deletionRequest->getCode(),
                ttlMinutes: self::DELETION_CODE_TTL_MINUTES,
            ));
        } catch (MailerExceptionInterface) {
            // Déjà journalisé par AbstractMailerHandler
            $this->userRequestService->revokeActiveRequests($user, UserRequestTypeEnum::AccountDeletion);

            return $this->sendJson(
                ['message' => 'Impossible d\'envoyer le code de confirmation pour le moment.'],
                Response::HTTP_BAD_GATEWAY
            );
        }

        $this->cache->save($sentItem->set(true)->expiresAfter(self::DELETION_CODE_RESEND_DELAY_SECONDS));
        $this->cache->deleteItem('gather_account_deletion_attempts_' . $user->getId());

        $this->generateLog(
            LoggerLevelEnum::Info,
            ['message' => 'Code de suppression de compte envoyé', 'data' => ['user_id' => (string) $user->getId()]],
            ['action' => 'account.delete.code_sent']
        );

        return $this->sendJson([
            'message'         => sprintf('Un code de confirmation valable %d minutes a été envoyé à l\'adresse %s.', self::DELETION_CODE_TTL_MINUTES, $user->getEmail()),
            'expiresIn'       => self::DELETION_CODE_TTL_MINUTES * 60,
            'resendAvailable' => self::DELETION_CODE_RESEND_DELAY_SECONDS,
        ]);
    }

    /**
     * DELETE /api/me : vérifie le code reçu par e-mail puis supprime le compte et ses données.
     * Au-delà de 5 essais erronés, le code est révoqué (un nouveau doit être demandé).
     */
    public function deleteAccount(Request $request): object
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->sendJson(['message' => 'Utilisateur non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($user->getRole() !== UserRole::ROLE_USER) {
            return $this->staffDeletionRefused();
        }

        try {
            /** @var DeleteAccountDto $dto */
            $dto = $this->serializer->deserialize($request->getContent() ?: '{}', DeleteAccountDto::class, 'json');
        } catch (Throwable) {
            return $this->sendJson(['message' => 'Format de données JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            return $this->sendViolations($violations);
        }

        $deletionRequest = $this->userRequestService->findValidRequestForUser($user, trim($dto->code), UserRequestTypeEnum::AccountDeletion);

        if (!$deletionRequest) {
            $attemptsItem = $this->cache->getItem('gather_account_deletion_attempts_' . $user->getId());
            $attempts = (int) $attemptsItem->get() + 1;
            $this->cache->save($attemptsItem->set($attempts)->expiresAfter(self::DELETION_CODE_TTL_MINUTES * 60));

            if ($attempts >= self::DELETION_CODE_MAX_ATTEMPTS) {
                $this->userRequestService->revokeActiveRequests($user, UserRequestTypeEnum::AccountDeletion);
                $this->generateLog(
                    LoggerLevelEnum::Warning,
                    ['message' => 'Code de suppression de compte révoqué après trop de tentatives', 'data' => ['user_id' => (string) $user->getId()]],
                    ['action' => 'account.delete.code_revoked']
                );

                return $this->sendJson(
                    ['message' => 'Trop de tentatives. Demandez un nouveau code de confirmation.'],
                    Response::HTTP_TOO_MANY_REQUESTS
                );
            }

            return $this->sendJson(['message' => 'Code de confirmation invalide ou expiré.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->userRequestService->consume($deletionRequest);
        $this->cache->deleteItem('gather_account_deletion_attempts_' . $user->getId());

        $res = $this->performAccountDeletion($user);

        if ($res->status === Response::HTTP_NO_CONTENT) {
            try {
                $this->security->logout(false);
            } catch (Throwable) {
                if ($request->hasSession()) {
                    $request->getSession()->invalidate();
                }
            }
        }

        return $res;
    }

    /**
     * Suppression du compte et de ses données une fois le code vérifié.
     */
    private function performAccountDeletion(User $user): object
    {
        try {
            $userId = (string) $user->getId();
            $this->accountDeletion->purge($user);

            $this->generateLog(
                LoggerLevelEnum::Info,
                ['message' => 'Compte supprimé après confirmation par code', 'data' => ['user_id' => $userId]],
                ['action' => 'account.delete.success']
            );

            return $this->sendNoContent();
        } catch (Throwable $e) {
            $this->generateLog(
                LoggerLevelEnum::Critical,
                [
                    'message'   => 'Erreur critique lors de la suppression du compte',
                    'data'      => ['user_id' => (string) $user->getId()],
                    'exception' => $e,
                ],
                ['action' => 'account.delete.critical_error']
            );

            return $this->sendJson(
                ['message' => 'Erreur interne lors de la suppression du compte.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /** Le journal de modération doit rester attribué à son auteur : le rôle est retiré d'abord par un administrateur. */
    private function staffDeletionRefused(): object
    {
        return $this->sendJson(
            ['message' => 'Un compte de l\'équipe de modération ne peut pas être supprimé d\'ici. Contactez un administrateur.'],
            Response::HTTP_FORBIDDEN
        );
    }

    private function getAuthenticatedUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    private function resolveRecipientName(User $user): string
    {
        $fullName = trim(sprintf('%s %s', (string) $user->getFirstname(), (string) $user->getLastname()));

        return $fullName !== '' ? $fullName : (string) ($user->getUsername() ?? 'Utilisateur');
    }
}
