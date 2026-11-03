<?php

declare(strict_types=1);

namespace App\Controller;

use Fagathe\GatherCore\Service\SupportService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Ouvert aux visiteurs : un problème de connexion ou d'inscription doit pouvoir être signalé. */
final class PublicSupportController extends ApiController
{
    public function __construct(
        private readonly SupportService $service,
    ) {}

    #[Route('/api/support/public', name: 'api_support_public', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->respond($this->service->createPublic($request));
    }
}
