<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Deux protections avant le pare-feu :
 *  - Origin : l'authentification passe par cookie, une requête d'écriture doit donc venir du front autorisé ;
 *  - débit : limite par adresse IP sur les routes coûteuses ou sensibles.
 */
final class ApiGuardSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $authLimiter,
        private readonly RateLimiterFactoryInterface $writeLimiter,
        private readonly RateLimiterFactoryInterface $linkPreviewLimiter,
        private readonly RateLimiterFactoryInterface $publicSupportLimiter,
        #[Autowire('%env(CORS_ALLOW_ORIGIN)%')]
        private readonly string $allowedOrigins,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 32]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api') || $request->isMethodSafe()) {
            return;
        }

        $origin = $request->headers->get('Origin');
        if ($origin !== null && preg_match('#' . str_replace('#', '\#', $this->allowedOrigins) . '#', $origin) !== 1) {
            $event->setResponse(new JsonResponse(['message' => 'Origine non autorisée.'], Response::HTTP_FORBIDDEN));

            return;
        }

        $limiter = $this->limiterFor($request);
        if (!$limiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
            $event->setResponse(new JsonResponse(['message' => 'Trop de requêtes, réessayez dans un instant.'], Response::HTTP_TOO_MANY_REQUESTS));
        }
    }

    private function limiterFor(Request $request): RateLimiterFactoryInterface
    {
        $path = $request->getPathInfo();

        return match (true) {
            preg_match('#^/api/auth/(login|register|forgot-password|reset-password|login-code|resend-verification)#', $path) === 1 => $this->authLimiter,
            $path === '/api/links/preview' => $this->linkPreviewLimiter,
            $path === '/api/support/public' => $this->publicSupportLimiter,
            default => $this->writeLimiter,
        };
    }
}
