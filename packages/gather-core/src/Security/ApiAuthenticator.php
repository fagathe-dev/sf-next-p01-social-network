<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Security;

use Fagathe\GatherCore\Repository\UserRepository;
use Fagathe\GatherCore\Security\Exception\ApiErrorCodeAwareInterface;
use Fagathe\GatherCore\Service\SecretDecoder;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\PasswordUpgradeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Authentification par identifiant (e-mail OU nom d'utilisateur) + mot de passe encodé en Base64.
 *
 * Flux :
 *  1. supports()                 → uniquement POST sur la route de login configurée ;
 *  2. authenticate()             → décodage du payload, mot de passe décodé via SecretDecoder,
 *                                  chargement de l'utilisateur via UserRepository::findByEmailOrUsername() ;
 *  3. Symfony                    → UserChecker::checkPreAuth → vérification du hash → checkPostAuth ;
 *  4. onAuthenticationSuccess()  → génération du JWT (Lexik) + utilisateur sérialisé (groupe user.read) ;
 *  5. onAuthenticationFailure()  → réponse JSON homogène, sans révéler si le compte existe.
 *
 * La classe vit dans gather-core pour être réutilisable ; c'est l'application hôte (gather_api)
 * qui la branche sur son firewall et lui fournit le chemin de login.
 */
final class ApiAuthenticator extends AbstractAuthenticator
{
    private const INVALID_CREDENTIALS_MESSAGE = 'Identifiants invalides.';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly SecretDecoder $secretDecoder,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly NormalizerInterface $normalizer,
        private readonly string $loginPath = '/api/auth/login',
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->isMethod(Request::METHOD_POST)
            && $request->getPathInfo() === $this->loginPath;
    }

    public function authenticate(Request $request): Passport
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            throw new CustomUserMessageAuthenticationException('Format de données JSON invalide.');
        }

        // Le front envoie le champ "username" (e-mail ou nom d'utilisateur) ; "email" est toléré.
        $identifier = trim((string) ($data['username'] ?? $data['email'] ?? ''));
        $password = $this->secretDecoder->decode((string) ($data['password'] ?? ''));

        if ($password === null) {
            throw new CustomUserMessageAuthenticationException('Format de données invalide.');
        }

        if ($identifier === '' || $password === '') {
            throw new CustomUserMessageAuthenticationException('Identifiant et mot de passe obligatoires.');
        }

        return new Passport(
            new UserBadge(
                $identifier,
                fn(string $userIdentifier) => $this->userRepository->findByEmailOrUsername($userIdentifier)
                    ?? throw new UserNotFoundException()
            ),
            // Mot de passe DÉCODÉ : c'est lui que Symfony compare au hash stocké.
            new PasswordCredentials($password),
            [
                // Re-hachage automatique si l'algorithme ou le coût évolue (UserRepository::upgradePassword).
                new PasswordUpgradeBadge($password, $this->userRepository),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();

        return new JsonResponse(
            [
                'token' => $this->jwtManager->create($user),
                'user'  => $this->normalizer->normalize($user, 'json', ['groups' => ['user.read']]),
            ],
            Response::HTTP_OK
        );
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Compte existant, mot de passe correct, mais statut bloquant (ex. e-mail non vérifié) → UserChecker.
        // Le code métier éventuel (ex. "email_not_verified") permet au front de proposer l'action adaptée.
        if ($exception instanceof AccountStatusException) {
            $payload = ['message' => $exception->getMessageKey()];
            if ($exception instanceof ApiErrorCodeAwareInterface) {
                $payload['code'] = $exception->getErrorCode();
            }

            return new JsonResponse($payload, Response::HTTP_FORBIDDEN);
        }

        // Payload mal formé ou incomplet : message explicite, aucune information sur le compte.
        if ($exception instanceof CustomUserMessageAuthenticationException) {
            return new JsonResponse(['message' => $exception->getMessageKey()], Response::HTTP_BAD_REQUEST);
        }

        // Utilisateur introuvable OU mot de passe erroné : même réponse (anti-énumération).
        return new JsonResponse(['message' => self::INVALID_CREDENTIALS_MESSAGE], Response::HTTP_UNAUTHORIZED);
    }
}
