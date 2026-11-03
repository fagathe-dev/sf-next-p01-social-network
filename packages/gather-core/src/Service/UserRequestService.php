<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Entity\UserRequest;
use Fagathe\GatherCore\Enum\Auth\UserRequestTypeEnum;
use Fagathe\GatherCore\Repository\UserRequestRepository;
use Fagathe\CorePhp\Enum\LoggerLevelEnum;
use Fagathe\CorePhp\Trait\DatetimeTrait;
use Fagathe\CorePhp\Trait\LoggerTrait;
use Throwable;

final class UserRequestService
{
    use DatetimeTrait, LoggerTrait;

    public function __construct(
        private readonly UserRequestRepository $repository,
    ) {}

    /**
     * Crée une demande (OTP à 6 chiffres ou jeton de 64 caractères) et révoque les demandes actives du même type.
     *
     * @param array<string, mixed>|null $payload
     * @param int|null                  $ttlSeconds Durée de validité en secondes (prioritaire sur $ttlMinutes)
     */
    public function createRequest(
        User $user,
        UserRequestTypeEnum $type,
        int $ttlMinutes = 15,
        bool $isOtp = true,
        ?array $payload = null,
        ?int $ttlSeconds = null,
    ): ?UserRequest {
        $now = $this->now();
        $expiresAt = $this->modifyDateTime(sprintf('+%d seconds', $ttlSeconds ?? $ttlMinutes * 60), $now);

        $this->invalidateActiveRequests($user, $type);

        $request = new UserRequest();
        $request
            ->setUser($user)
            ->setType($type)
            ->setCreatedAt($now)
            ->setExpiresAt($expiresAt)
            ->setPayload($payload);

        if ($isOtp) {
            $request->setCode((string) random_int(100000, 999999));
        } else {
            $request->setToken(bin2hex(random_bytes(32)));
        }

        try {
            $this->repository->save($request, flush: true, isCreation: true);

            $this->generateLog(
                LoggerLevelEnum::Info,
                [
                    'message' => 'Demande utilisateur créée avec succès',
                    'data'    => [
                        'user_id'    => (string) $user->getId(),
                        'type'       => $type->value,
                        'is_otp'     => $isOtp,
                        'expires_at' => $this->formatDateTime('Y-m-d H:i:s', $expiresAt),
                    ],
                ],
                ['action' => 'user_request.create.success']
            );

            return $request;
        } catch (Throwable $e) {
            $this->generateLog(
                LoggerLevelEnum::Critical,
                [
                    'message'   => 'Échec lors de la création de la UserRequest',
                    'data'      => ['user_id' => (string) $user->getId()],
                    'exception' => $e,
                ],
                ['action' => 'user_request.create.error']
            );

            return null;
        }
    }

    public function findValidRequest(string $identifier, UserRequestTypeEnum $type): ?UserRequest
    {
        /** @var UserRequest|null $userRequest */
        $userRequest = $this->repository->findOneBy([
            'code'   => $identifier,
            'type'   => $type,
            'isUsed' => false,
        ]) ?? $this->repository->findOneBy([
            'token'  => $identifier,
            'type'   => $type,
            'isUsed' => false,
        ]);

        if (!$userRequest) {
            return null;
        }

        if ($this->isPastDate($userRequest->getExpiresAt())) {
            $this->consume($userRequest);

            return null;
        }

        return $userRequest;
    }

    /**
     * Vérifie un code / jeton parmi les demandes d'un utilisateur donné uniquement.
     * Indispensable pour les codes à 6 chiffres, qui ne sont pas uniques entre utilisateurs.
     */
    public function findValidRequestForUser(User $user, string $identifier, UserRequestTypeEnum $type): ?UserRequest
    {
        /** @var UserRequest|null $userRequest */
        $userRequest = $this->repository->findOneBy([
            'user'   => $user,
            'code'   => $identifier,
            'type'   => $type,
            'isUsed' => false,
        ]) ?? $this->repository->findOneBy([
            'user'   => $user,
            'token'  => $identifier,
            'type'   => $type,
            'isUsed' => false,
        ]);

        if (!$userRequest) {
            return null;
        }

        if ($this->isPastDate($userRequest->getExpiresAt())) {
            $this->consume($userRequest);

            return null;
        }

        return $userRequest;
    }

    /**
     * Révoque toutes les demandes actives d'un type (ex. trop de tentatives sur un code).
     */
    public function revokeActiveRequests(User $user, UserRequestTypeEnum $type): void
    {
        $revoked = $this->invalidateActiveRequests($user, $type);
        $last = array_key_last($revoked);

        foreach ($revoked as $index => $request) {
            $this->repository->save($request, flush: $index === $last, isCreation: false);
        }
    }

    public function consume(UserRequest $request): void
    {
        $request->setIsUsed(true);
        $request->setUpdatedAt($this->now());
        $this->repository->save($request, flush: true, isCreation: false);
    }

    /**
     * Marque les demandes actives comme utilisées (persistées au prochain flush).
     *
     * @return list<UserRequest>
     */
    private function invalidateActiveRequests(User $user, UserRequestTypeEnum $type): array
    {
        $activeRequests = $this->repository->findBy([
            'user'   => $user,
            'type'   => $type,
            'isUsed' => false,
        ]);

        $now = $this->now();
        foreach ($activeRequests as $req) {
            $req->setIsUsed(true);
            $req->setUpdatedAt($now);
        }

        return array_values($activeRequests);
    }
}
