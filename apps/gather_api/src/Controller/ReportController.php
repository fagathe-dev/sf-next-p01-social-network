<?php

declare(strict_types=1);

namespace App\Controller;

use Fagathe\GatherCore\Service\ModerationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ReportController extends ApiController
{
    #[Route('/api/reports', name: 'api_reports_create', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function create(Request $request, ModerationService $service): JsonResponse
    {
        return $this->respond($service->report($request));
    }
}
