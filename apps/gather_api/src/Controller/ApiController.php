<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

abstract class ApiController extends AbstractController
{
    /** Convertit la réponse d'un service de gather-core (ResponseTrait) en JSON. */
    protected function respond(object $result): JsonResponse
    {
        return $this->json($result->data, $result->status, $result->headers, $result->context);
    }
}
