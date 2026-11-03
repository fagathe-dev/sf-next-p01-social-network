<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Generator;

use Fagathe\CorePhp\Http\RequestTrait;

/**
 * Construit les liens des e-mails d'authentification vers l'application cliente,
 * configurée via gather_core.app_url.
 */
final class AuthLinkGenerator
{
    use RequestTrait;

    /** Chemins par défaut ; chaque app peut les surcharger via gather_core.auth_paths */
    public const VERIFY_EMAIL_PATH = '/verify-email';
    public const RESET_PASSWORD_PATH = '/reset-password';
    public const CONFIRM_EMAIL_CHANGE_PATH = '/confirm-email-change';
    public const LOGIN_PATH = '/login';

    public function __construct(
        private readonly ?string $appUrl = null,
        private readonly string $verifyEmailPath = self::VERIFY_EMAIL_PATH,
        private readonly string $resetPasswordPath = self::RESET_PASSWORD_PATH,
        private readonly string $confirmEmailChangePath = self::CONFIRM_EMAIL_CHANGE_PATH,
        private readonly string $loginPath = self::LOGIN_PATH,
    ) {}

    public function verifyEmailUrl(string $token): string
    {
        return $this->build($this->verifyEmailPath, ['token' => $token]);
    }

    public function resetPasswordUrl(string $token): string
    {
        return $this->build($this->resetPasswordPath, ['token' => $token]);
    }

    public function confirmEmailChangeUrl(string $token): string
    {
        return $this->build($this->confirmEmailChangePath, ['token' => $token]);
    }

    public function groupUrl(string $slug): string
    {
        return $this->build('/g/' . rawurlencode($slug));
    }

    public function conversationUrl(string $id): string
    {
        return $this->build('/messages/' . rawurlencode($id));
    }

    public function ticketUrl(string $id): string
    {
        return $this->build('/support/' . rawurlencode($id));
    }

    /** Page du front qui vérifie la session puis renvoie au back-office avec un jeton à usage unique. */
    public function adminSsoUrl(string $target = ''): string
    {
        return $this->build('/sso/admin', $target !== '' ? ['target' => $target] : []);
    }

    public function loginUrl(): string
    {
        return $this->build($this->loginPath);
    }

    /**
     * @param array<string, string> $query
     */
    private function build(string $path, array $query = []): string
    {
        $baseUrl = rtrim(!empty($this->appUrl) ? $this->appUrl : $this->getOrigin(), '/');

        return $baseUrl . $path . ($query !== [] ? '?' . http_build_query($query) : '');
    }
}
