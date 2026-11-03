<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Fagathe\GatherCore\Dto\Auth\ForgotPasswordDto;
use Fagathe\GatherCore\Dto\Auth\IdentifierDto;
use Fagathe\GatherCore\Dto\Auth\LoginCodeDto;
use Fagathe\GatherCore\Dto\Auth\ResetPasswordDto;
use Fagathe\GatherCore\Dto\Auth\VerifyEmailDto;
use Fagathe\GatherCore\Dto\Mail\AccountVerificationMailDto;
use Fagathe\GatherCore\Dto\Mail\ForgotPasswordMailDto;
use Fagathe\GatherCore\Dto\Mail\LoginCodeMailDto;
use Fagathe\GatherCore\Dto\Mail\RegistrationConfirmedMailDto;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Auth\UserRequestTypeEnum;
use Fagathe\GatherCore\Enum\Auth\UserRole;
use Fagathe\GatherCore\Generator\AuthLinkGenerator;
use Fagathe\GatherCore\Mailer\MailDispatcher;
use Fagathe\GatherCore\Repository\UserRepository;
use Fagathe\CorePhp\Enum\LoggerLevelEnum;
use Fagathe\CorePhp\Http\RequestTrait;
use Fagathe\CorePhp\Http\ResponseTrait;
use Fagathe\CorePhp\Mailer\Exception\MailerExceptionInterface;
use Fagathe\CorePhp\Mailer\Exception\RecipientUnreachableException;
use Fagathe\CorePhp\Trait\DatetimeTrait;
use Fagathe\CorePhp\Trait\LoggerTrait;
use Fagathe\GatherCore\Security\UserChecker;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

/**
 * Authentification : inscription + vérification de l'e-mail (lien 72h, connexion automatique),
 * connexion sans mot de passe (code 1 min 30) et mot de passe oublié (lien 24h).
 *
 * La connexion par mot de passe passe par Fagathe\GatherCore\Security\ApiAuthenticator.
 */
final class AuthService
{
    use ResponseTrait, DatetimeTrait, LoggerTrait, RequestTrait;

    /** Validité du lien de vérification envoyé à l'inscription */
    public const VERIFICATION_TTL_HOURS = 72;

    /** Validité du lien de réinitialisation du mot de passe */
    public const PASSWORD_RESET_TTL_HOURS = 24;

    /** Connexion sans mot de passe : validité du code, essais autorisés, délai entre deux envois */
    public const LOGIN_CODE_TTL_SECONDS = 90;
    public const LOGIN_CODE_VALIDITY_LABEL = '1 min 30';
    public const LOGIN_CODE_MAX_ATTEMPTS = 5;
    public const LOGIN_CODE_RESEND_DELAY_SECONDS = 30;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ValidatorInterface $validator,
        private readonly SerializerInterface $serializer,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly UserRequestService $userRequestService,
        private readonly SecretDecoder $secretDecoder,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly MailDispatcher $mailDispatcher,
        private readonly AuthLinkGenerator $linkGenerator,
        private readonly CacheItemPoolInterface $cache,
        private readonly UserChecker $userChecker,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Inscription & vérification du compte
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Inscription et envoi du lien de vérification (72h).
     * Le compte reste inactif (connexion refusée par UserChecker) tant que le lien n'a pas été utilisé.
     */
    public function register(Request $request): object
    {
        $now = $this->now();

        // Le mot de passe arrive en base64 : on le décode AVANT la désérialisation et la validation
        $body = $this->secretDecoder->decodeJsonBody($request->getContent(), ['password']);
        if ($body === null) {
            return $this->sendJson(['message' => 'Format de données invalide.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            /** @var User $user */
            $user = $this->serializer->deserialize(
                $body,
                User::class,
                'json',
                [
                    AbstractNormalizer::IGNORED_ATTRIBUTES => [
                        'id',
                        'roles',
                        'role',
                        'createdAt',
                        'updatedAt',
                        'avatar',
                        'settings',
                        'emailVerifiedAt',
                        'isVerified',
                        'status',
                        'effectiveStatus',
                        'sanctionExpiresAt',
                        'warningsCount',
                        'invitedBy',
                        'isPrivate',
                        'banner',
                        'deactivatedAt',
                        'isExcludedFromSearch',
                        'excludedFromSearch',
                    ],
                ]
            );
        } catch (Throwable) {
            return $this->sendJson(['message' => 'Format de données JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        // Validation des contraintes de l'entité (mot de passe décodé en clair ici)
        $violations = $this->validator->validate($user);
        if (count($violations) > 0) {
            return $this->sendViolations($violations);
        }

        $birthDate = $user->getBirthDate();
        if ($birthDate === null) {
            return $this->sendViolations(['birthDate' => 'La date de naissance est obligatoire.']);
        }
        if (!User::isOldEnough($birthDate)) {
            return $this->sendViolations(['birthDate' => sprintf('Vous devez avoir au moins %d ans pour vous inscrire.', User::MINIMUM_AGE)]);
        }

        $user
            ->setPassword($this->hasher->hashPassword($user, (string) $user->getPassword()))
            ->setRole(UserRole::ROLE_USER)
            ->setCreatedAt($now)
            ->setIsVerified(false);

        if (!$this->userRepository->save($user, flush: true, isCreation: true)) {
            $this->generateLog(
                LoggerLevelEnum::Critical,
                [
                    'message' => 'Erreur critique lors de l\'inscription',
                    'data'    => ['user' => $user->getUsername(), 'user_agent' => $this->getUserAgent()],
                ],
                ['action' => 'user.register.critical_error']
            );

            return $this->sendJson(
                ['message' => 'Erreur interne lors de la création du compte.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        try {
            $this->sendVerificationEmail($user);
        } catch (RecipientUnreachableException) {
            $this->cancelRegistration($user);

            return $this->sendJson(
                ['message' => 'Le domaine de cette adresse e-mail semble invalide ou ne peut pas recevoir de messages.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        } catch (MailerExceptionInterface) {
            // Déjà journalisé par AbstractMailerHandler
            $this->cancelRegistration($user);

            return $this->sendJson(
                ['message' => 'Impossible d\'envoyer l\'e-mail de vérification pour le moment. Veuillez réessayer.'],
                Response::HTTP_BAD_GATEWAY
            );
        } catch (Throwable $e) {
            $this->cancelRegistration($user);
            $this->generateLog(
                LoggerLevelEnum::Critical,
                [
                    'message'   => 'Erreur critique lors de l\'envoi de l\'e-mail de vérification',
                    'data'      => ['user' => $user->getUsername()],
                    'exception' => $e,
                ],
                ['action' => 'user.register.critical_error']
            );

            return $this->sendJson(
                ['message' => 'Erreur interne lors de la création du compte.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $this->generateLog(
            LoggerLevelEnum::Info,
            ['message' => 'Compte créé, lien de vérification envoyé', 'data' => ['user_id' => (string) $user->getId()]],
            ['action' => 'user.register.success']
        );

        return $this->sendJson(
            [
                'user'    => $user,
                'message' => sprintf(
                    'Compte créé avec succès. Un lien de vérification valable %dh vous a été envoyé à l\'adresse %s.',
                    self::VERIFICATION_TTL_HOURS,
                    $user->getEmail()
                ),
            ],
            Response::HTTP_CREATED,
            context: ['groups' => ['user.read']]
        );
    }

    /**
     * Lien reçu à l'inscription : active le compte et connecte automatiquement l'utilisateur (JWT).
     */
    public function verifyEmail(Request $request): object
    {
        $dto = $this->deserialize($request->getContent(), VerifyEmailDto::class);
        if ($dto === null) {
            return $this->sendJson(['message' => 'Format de données JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            return $this->sendViolations($violations);
        }

        $userRequest = $this->userRequestService->findValidRequest(trim($dto->token), UserRequestTypeEnum::EmailVerification);
        if (!$userRequest) {
            return $this->sendJson(
                ['message' => 'Ce lien de vérification est invalide ou a expiré. Demandez-en un nouveau depuis la page de connexion.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $user = $userRequest->getUser();
        $wasVerified = (bool) $user->isVerified();

        $user->setIsVerified(true)->setEmailVerifiedAt($this->now());
        $this->userRepository->save($user, flush: false, isCreation: false);
        // Consomme la demande et enregistre l'activation du compte
        $this->userRequestService->consume($userRequest);

        if (!$wasVerified) {
            $this->mailDispatcher->dispatchSilently(new RegistrationConfirmedMailDto(
                recipientEmail: (string) $user->getEmail(),
                recipientName: $this->resolveRecipientName($user),
                loginUrl: $this->linkGenerator->loginUrl(),
            ));
        }

        $this->generateLog(
            LoggerLevelEnum::Info,
            ['message' => 'Adresse e-mail vérifiée', 'data' => ['user_id' => (string) $user->getId()]],
            ['action' => 'user.verify_email.success']
        );

        return $this->sendAuthenticated($user, 'Votre compte a été vérifié avec succès. Bienvenue !');
    }

    /**
     * Renvoi du lien de vérification (lien expiré ou e-mail perdu). Réponse constante (anti-énumération).
     */
    public function resendVerification(Request $request): object
    {
        $dto = $this->deserialize($request->getContent(), IdentifierDto::class);
        if ($dto === null) {
            return $this->sendJson(['message' => 'Format de données JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            return $this->sendViolations($violations);
        }

        $user = $this->userRepository->findByEmailOrUsername(trim($dto->username));

        if ($user instanceof User && !$user->isVerified()) {
            try {
                $this->sendVerificationEmail($user);
            } catch (Throwable) {
                // Échec déjà journalisé (AbstractMailerHandler) : la réponse reste identique
            }
        }

        return $this->sendJson([
            'message' => 'Si ce compte est en attente de vérification, un nouveau lien vous a été envoyé par e-mail.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Connexion sans mot de passe (code temporaire par e-mail)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Étape 1 : envoi d'un code à 6 chiffres valable 1 min 30.
     * Réponse constante (anti-énumération) ; un nouvel envoi n'est possible qu'après 30 secondes.
     */
    public function requestLoginCode(Request $request): object
    {
        $dto = $this->deserialize($request->getContent(), IdentifierDto::class);
        if ($dto === null) {
            return $this->sendJson(['message' => 'Format de données JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            return $this->sendViolations($violations);
        }

        $user = $this->userRepository->findByEmailOrUsername(trim($dto->username));

        if ($user instanceof User && $user->isVerified() && $this->acquireLoginCodeSendSlot($user)) {
            $loginRequest = $this->userRequestService->createRequest(
                user: $user,
                type: UserRequestTypeEnum::OtpLogin,
                isOtp: true,
                ttlSeconds: self::LOGIN_CODE_TTL_SECONDS,
            );

            if ($loginRequest?->getCode() !== null) {
                $this->resetLoginCodeAttempts($user);

                $this->mailDispatcher->dispatchSilently(new LoginCodeMailDto(
                    recipientEmail: (string) $user->getEmail(),
                    recipientName: $this->resolveRecipientName($user),
                    code: $loginRequest->getCode(),
                    validityLabel: self::LOGIN_CODE_VALIDITY_LABEL,
                ));
            }
        }

        return $this->sendJson([
            'message'         => 'Si ce compte existe, un code de connexion vous a été envoyé par e-mail.',
            'expiresIn'       => self::LOGIN_CODE_TTL_SECONDS,
            'resendAvailable' => self::LOGIN_CODE_RESEND_DELAY_SECONDS,
        ]);
    }

    /**
     * Étape 2 : vérification du code pour CET utilisateur.
     * Au-delà de 5 essais erronés, le code est révoqué (un nouveau doit être demandé).
     */
    public function loginWithCode(Request $request): object
    {
        $dto = $this->deserialize($request->getContent(), LoginCodeDto::class);
        if ($dto === null) {
            return $this->sendJson(['message' => 'Format de données JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            return $this->sendViolations($violations);
        }

        $invalidCode = ['message' => 'Code invalide ou expiré.'];

        $user = $this->userRepository->findByEmailOrUsername(trim($dto->username));
        if (!$user instanceof User || !$user->isVerified()) {
            return $this->sendJson($invalidCode, Response::HTTP_UNAUTHORIZED);
        }

        $loginRequest = $this->userRequestService->findValidRequestForUser($user, trim($dto->code), UserRequestTypeEnum::OtpLogin);

        if (!$loginRequest) {
            if ($this->registerFailedLoginCodeAttempt($user) >= self::LOGIN_CODE_MAX_ATTEMPTS) {
                $this->userRequestService->revokeActiveRequests($user, UserRequestTypeEnum::OtpLogin);

                return $this->sendJson(
                    ['message' => 'Trop de tentatives. Demandez un nouveau code de connexion.'],
                    Response::HTTP_TOO_MANY_REQUESTS
                );
            }

            return $this->sendJson($invalidCode, Response::HTTP_UNAUTHORIZED);
        }

        $this->userRequestService->consume($loginRequest);
        $this->resetLoginCodeAttempts($user);

        // Même contrôle que la connexion par mot de passe, qui passe par le pare-feu
        try {
            $this->userChecker->checkPreAuth($user);
        } catch (CustomUserMessageAccountStatusException $e) {
            return $this->sendJson(['message' => $e->getMessageKey()], Response::HTTP_FORBIDDEN);
        }

        $this->generateLog(
            LoggerLevelEnum::Info,
            ['message' => 'Connexion par code', 'data' => ['user_id' => (string) $user->getId()]],
            ['action' => 'user.login_code.success']
        );

        return $this->sendAuthenticated($user);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Mot de passe oublié
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Demande de réinitialisation : envoi d'un lien valable 24h. Réponse constante (anti-énumération).
     */
    public function forgotPassword(Request $request): object
    {
        $dto = $this->deserialize($request->getContent(), ForgotPasswordDto::class);
        if ($dto === null) {
            return $this->sendJson(['message' => 'Format de données JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            return $this->sendViolations($violations);
        }

        $user = $this->userRepository->findOneBy(['email' => trim($dto->email)]);

        if ($user instanceof User) {
            $userRequest = $this->userRequestService->createRequest(
                user: $user,
                type: UserRequestTypeEnum::PasswordReset,
                ttlMinutes: self::PASSWORD_RESET_TTL_HOURS * 60,
                isOtp: false
            );

            if ($userRequest?->getToken() !== null) {
                // Échec déjà journalisé par AbstractMailerHandler : réponse constante (anti-énumération)
                $this->mailDispatcher->dispatchSilently(new ForgotPasswordMailDto(
                    recipientEmail: (string) $user->getEmail(),
                    recipientName: $this->resolveRecipientName($user),
                    resetUrl: $this->linkGenerator->resetPasswordUrl($userRequest->getToken()),
                    ttlHours: self::PASSWORD_RESET_TTL_HOURS,
                ));
            }
        }

        return $this->sendJson([
            'message' => 'Si cette adresse est associée à un compte, un lien de réinitialisation vous a été envoyé.',
        ]);
    }

    /**
     * Réinitialisation effective : le nouveau mot de passe (base64) est validé puis enregistré.
     */
    public function resetPassword(Request $request): object
    {
        $body = $this->secretDecoder->decodeJsonBody($request->getContent(), ['password']);
        $dto = $body !== null ? $this->deserialize($body, ResetPasswordDto::class) : null;
        if ($dto === null) {
            return $this->sendJson(['message' => 'Format de données invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            return $this->sendViolations($violations);
        }

        $userRequest = $this->userRequestService->findValidRequest(trim($dto->token), UserRequestTypeEnum::PasswordReset);
        if (!$userRequest) {
            return $this->sendJson(
                ['message' => 'Le lien de réinitialisation est invalide ou a expiré. Faites une nouvelle demande.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $user = $userRequest->getUser();
        $user->setPassword($this->hasher->hashPassword($user, $dto->password));

        // Le lien reçu prouve la possession de l'adresse : le compte peut être activé
        if (!$user->isVerified()) {
            $user->setIsVerified(true)->setEmailVerifiedAt($this->now());
        }

        $this->userRepository->save($user, flush: false, isCreation: false);
        // Consomme la demande et enregistre le nouveau mot de passe
        $this->userRequestService->consume($userRequest);

        $this->generateLog(
            LoggerLevelEnum::Info,
            ['message' => 'Mot de passe réinitialisé', 'data' => ['user_id' => (string) $user->getId()]],
            ['action' => 'user.password_reset.success']
        );

        return $this->sendJson(['message' => 'Votre mot de passe a été réinitialisé avec succès. Vous pouvez maintenant vous connecter.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function deserialize(string $json, string $class): ?object
    {
        try {
            return $this->serializer->deserialize($json, $class, 'json');
        } catch (Throwable) {
            return null;
        }
    }

    private function sendAuthenticated(User $user, ?string $message = null): object
    {
        return $this->sendJson(
            array_filter([
                'token'   => $this->jwtManager->create($user),
                'user'    => $user,
                'message' => $message,
            ], static fn (mixed $value): bool => $value !== null),
            Response::HTTP_OK,
            context: ['groups' => ['user.read']]
        );
    }

    /**
     * Crée un lien de vérification (72h) et l'envoie.
     *
     * @throws MailerExceptionInterface
     */
    private function sendVerificationEmail(User $user): void
    {
        $verificationRequest = $this->userRequestService->createRequest(
            user: $user,
            type: UserRequestTypeEnum::EmailVerification,
            ttlMinutes: self::VERIFICATION_TTL_HOURS * 60,
            isOtp: false
        );

        if ($verificationRequest?->getToken() === null) {
            throw new \RuntimeException('Impossible de générer le lien de vérification.');
        }

        $this->mailDispatcher->dispatch(new AccountVerificationMailDto(
            recipientEmail: (string) $user->getEmail(),
            recipientName: $this->resolveRecipientName($user),
            verificationUrl: $this->linkGenerator->verifyEmailUrl($verificationRequest->getToken()),
            ttlHours: self::VERIFICATION_TTL_HOURS,
        ));
    }

    /**
     * Annule une inscription dont l'e-mail de vérification n'a pas pu partir,
     * pour libérer l'e-mail et le username (les UserRequest sont supprimées en cascade).
     */
    private function cancelRegistration(User $user): void
    {
        if (!$this->userRepository->remove($user, flush: true)) {
            $this->generateLog(
                LoggerLevelEnum::Error,
                ['message' => 'Impossible d\'annuler l\'inscription après un échec d\'envoi', 'data' => ['user' => $user->getUsername()]],
                ['action' => 'user.register.rollback_error']
            );
        }
    }

    /**
     * true si aucun code n'a été envoyé à cet utilisateur depuis 30 secondes (limite le spam d'e-mails).
     */
    private function acquireLoginCodeSendSlot(User $user): bool
    {
        $item = $this->cache->getItem('gather_login_code_sent_' . $user->getId());
        if ($item->isHit()) {
            return false;
        }

        $this->cache->save($item->set(true)->expiresAfter(self::LOGIN_CODE_RESEND_DELAY_SECONDS));

        return true;
    }

    /**
     * @return int Nombre d'essais erronés pour le code en cours
     */
    private function registerFailedLoginCodeAttempt(User $user): int
    {
        $item = $this->cache->getItem('gather_login_code_attempts_' . $user->getId());
        $attempts = (int) $item->get() + 1;

        $this->cache->save($item->set($attempts)->expiresAfter(self::LOGIN_CODE_TTL_SECONDS));

        return $attempts;
    }

    private function resetLoginCodeAttempts(User $user): void
    {
        $this->cache->deleteItem('gather_login_code_attempts_' . $user->getId());
    }

    private function resolveRecipientName(User $user): string
    {
        $fullName = trim(sprintf('%s %s', (string) $user->getFirstname(), (string) $user->getLastname()));

        return $fullName !== '' ? $fullName : (string) ($user->getUsername() ?? 'Utilisateur');
    }
}
