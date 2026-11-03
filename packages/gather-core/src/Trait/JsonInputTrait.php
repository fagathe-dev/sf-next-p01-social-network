<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Trait;

use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Désérialise et valide un DTO d'entrée. Attend `serializer`, `validator` et ResponseTrait sur la classe hôte.
 */
trait JsonInputTrait
{
    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed>|string $payload JSON brut ou champs déjà extraits (multipart)
     *
     * @return T|object le DTO, ou la réponse d'erreur (voir isErrorResponse)
     */
    private function readInput(array|string $payload, string $class): object
    {
        try {
            $dto = is_string($payload)
                ? $this->serializer->deserialize($payload === '' ? '{}' : $payload, $class, 'json')
                : $this->serializer->denormalize($payload, $class);
        } catch (Throwable) {
            return $this->sendJson(['message' => 'Format de données invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            return $this->sendViolations($violations);
        }

        return $dto;
    }

    private function isErrorResponse(object $result, string $class): bool
    {
        return !$result instanceof $class;
    }
}
