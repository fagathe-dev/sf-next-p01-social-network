<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\RefreshToken;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Les jetons vivent dans des cookies HttpOnly : invisibles du JavaScript, lisibles au rendu SSR de Next.js.
 */
final class AuthCookieManager
{
    public const ACCESS_COOKIE = 'BEARER';
    public const REFRESH_COOKIE = 'REFRESH_TOKEN';
    private const ACCESS_TTL = 900;
    private const REFRESH_TTL = 2592000;

    public function __construct(
        private readonly RefreshTokenGeneratorInterface $generator,
        private readonly RefreshTokenManagerInterface $manager,
        #[Autowire('%env(bool:AUTH_COOKIE_SECURE)%')]
        private readonly bool $secure,
        #[Autowire('%env(default::AUTH_COOKIE_DOMAIN)%')]
        private readonly ?string $domain,
    ) {}

    public function issue(Response $response, Request $request, string $jwt, UserInterface $user): void
    {
        /** @var RefreshToken $refresh */
        $refresh = $this->generator->createForUserWithTtl($user, self::REFRESH_TTL);
        $refresh->setUserAgent($request->headers->get('User-Agent'))->setCreatedAt(new \DateTimeImmutable());
        $this->manager->save($refresh);

        // Même durée que le JWT : le cookie expiré disparaît, le visiteur redevient anonyme au lieu de recevoir un 401
        $response->headers->setCookie($this->cookie(self::ACCESS_COOKIE, $jwt, self::ACCESS_TTL, '/'));
        $response->headers->setCookie($this->cookie(self::REFRESH_COOKIE, (string) $refresh->getRefreshToken(), self::REFRESH_TTL, '/api'));
    }

    public function clear(Response $response): void
    {
        $response->headers->clearCookie(self::ACCESS_COOKIE, '/', $this->domain ?: null, $this->secure, true, Cookie::SAMESITE_LAX);
        $response->headers->clearCookie(self::REFRESH_COOKIE, '/api', $this->domain ?: null, $this->secure, true, Cookie::SAMESITE_LAX);
    }

    private function cookie(string $name, string $value, int $ttl, string $path): Cookie
    {
        return Cookie::create($name)
            ->withValue($value)
            ->withExpires(time() + $ttl)
            ->withPath($path)
            ->withDomain($this->domain ?: null)
            ->withSecure($this->secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }
}
