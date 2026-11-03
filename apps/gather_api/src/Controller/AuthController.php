<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\RefreshToken;
use App\Security\AuthCookieManager;
use Fagathe\GatherCore\Repository\UserRepository;
use Fagathe\GatherCore\Security\UserChecker;
use Fagathe\GatherCore\Service\AccountService;
use Fagathe\GatherCore\Service\AuthService;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Le JWT renvoyé par ces routes est déplacé dans un cookie HttpOnly par AuthCookieSubscriber.
 */
#[Route('/api/auth', name: 'api_auth_')]
final class AuthController extends ApiController
{
    public function __construct(
        private readonly AuthService $service,
    ) {}

    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(): never
    {
        throw new \LogicException('Cette route est gérée par ApiAuthenticator (firewall "auth").');
    }

    /** Connexion sans mot de passe, étape 1 : envoi du code par e-mail. */
    #[Route('/login-code', name: 'login_code_request', methods: ['POST'])]
    public function requestLoginCode(Request $request): JsonResponse
    {
        return $this->respond($this->service->requestLoginCode($request));
    }

    /** Connexion sans mot de passe, étape 2 : identifiant et code. */
    #[Route('/login-code/verify', name: 'login_code_verify', methods: ['POST'])]
    public function loginWithCode(Request $request): JsonResponse
    {
        return $this->respond($this->service->loginWithCode($request));
    }

    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        return $this->respond($this->service->register($request));
    }

    #[Route('/verify-email', name: 'verify_email', methods: ['POST'])]
    public function verifyEmail(Request $request): JsonResponse
    {
        return $this->respond($this->service->verifyEmail($request));
    }

    #[Route('/resend-verification', name: 'resend_verification', methods: ['POST'])]
    public function resendVerification(Request $request): JsonResponse
    {
        return $this->respond($this->service->resendVerification($request));
    }

    #[Route('/forgot-password', name: 'forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        return $this->respond($this->service->forgotPassword($request));
    }

    #[Route('/reset-password', name: 'reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        return $this->respond($this->service->resetPassword($request));
    }

    /** Lien reçu sur la nouvelle adresse : public, le jeton fait foi. */
    #[Route('/confirm-email-change', name: 'confirm_email_change', methods: ['POST'])]
    public function confirmEmailChange(Request $request, AccountService $account): JsonResponse
    {
        return $this->respond($account->confirmEmailChange($request));
    }

    /** Rotation : le refresh token utilisé est détruit, un nouveau est émis avec le JWT. */
    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(
        Request $request,
        RefreshTokenManagerInterface $tokens,
        UserRepository $users,
        JWTTokenManagerInterface $jwtManager,
        NormalizerInterface $normalizer,
        AuthCookieManager $cookies,
        UserChecker $userChecker,
    ): JsonResponse {
        $invalid = function () use ($cookies): JsonResponse {
            $response = $this->json(['message' => 'Session expirée.'], Response::HTTP_UNAUTHORIZED);
            $cookies->clear($response);

            return $response;
        };

        $value = $request->cookies->get(AuthCookieManager::REFRESH_COOKIE);
        /** @var RefreshToken|null $refresh */
        $refresh = is_string($value) ? $tokens->get($value) : null;
        if ($refresh === null || !$refresh->isValid()) {
            return $invalid();
        }

        $user = $users->findByEmailOrUsername((string) $refresh->getUsername());
        $tokens->delete($refresh);
        if ($user === null) {
            return $invalid();
        }

        try {
            $userChecker->checkPreAuth($user);
        } catch (CustomUserMessageAccountStatusException) {
            return $invalid();
        }

        return $this->json([
            'token' => $jwtManager->create($user),
            'user' => $normalizer->normalize($user, 'json', ['groups' => ['user.read']]),
        ]);
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request, RefreshTokenManagerInterface $tokens, AuthCookieManager $cookies): JsonResponse
    {
        $value = $request->cookies->get(AuthCookieManager::REFRESH_COOKIE);
        $refresh = is_string($value) ? $tokens->get($value) : null;
        if ($refresh !== null) {
            $tokens->delete($refresh);
        }

        $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);
        $cookies->clear($response);

        return $response;
    }
}
