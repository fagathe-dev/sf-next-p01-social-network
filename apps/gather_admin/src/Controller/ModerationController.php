<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Fagathe\GatherCore\Dto\Input\ModerationDecisionInput;
use Fagathe\GatherCore\Entity\Report;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Moderation\ModerationActionType;
use Fagathe\GatherCore\Enum\Moderation\ReportStatus;
use Fagathe\GatherCore\Enum\Moderation\ReportTargetType;
use Fagathe\GatherCore\Repository\ReportRepository;
use Fagathe\GatherCore\Service\ModerationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

#[Route('/moderation/reports', name: 'admin_')]
final class ModerationController extends AbstractController
{
    #[Route('', name: 'reports', methods: ['GET'])]
    public function reports(Request $request, ReportRepository $reports): Response
    {
        $status = ReportStatus::tryFrom($request->query->getString('status', ReportStatus::Pending->value));
        $type = ReportTargetType::tryFrom($request->query->getString('type'));
        $page = max(1, $request->query->getInt('page', 1));

        // « Pending » couvre aussi les contenus masqués : ils restent à traiter et passent en tête
        $queue = $reports->queue($status === ReportStatus::Pending ? null : $status, $type, $page);
        if ($status === ReportStatus::Pending) {
            $queue['items'] = array_values(array_filter($queue['items'], static fn(Report $r) => $r->getStatus() !== ReportStatus::Resolved));
        }

        return $this->render('moderation/reports.html.twig', [
            'queue' => $queue,
            'status' => $status,
            'type' => $type,
            'statuses' => ReportStatus::cases(),
            'types' => ReportTargetType::cases(),
            'actions' => ModerationActionType::cases(),
        ]);
    }

    #[Route('/{id}/resolve', name: 'report_resolve', methods: ['POST'])]
    public function resolve(string $id, Request $request, EntityManagerInterface $em, ModerationService $moderation, #[CurrentUser] User $moderator): Response
    {
        if (!$this->isCsrfTokenValid('resolve' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $report = Uuid::isValid($id) ? $em->find(Report::class, Uuid::fromString($id)) : null;
        if ($report === null) {
            throw $this->createNotFoundException();
        }

        $action = ModerationActionType::tryFrom($request->request->getString('action'));
        $reason = trim($request->request->getString('reason'));
        // Champ facultatif : getInt() refuse une chaîne vide
        $rawDays = $request->request->getString('durationDays');
        $days = ctype_digit($rawDays) ? (int) $rawDays : null;

        if ($action === null || $reason === '') {
            $this->addFlash('error', 'Choisissez une action et indiquez un motif.');
        } else {
            $error = $moderation->resolve($report, $moderator, new ModerationDecisionInput($action, $reason, $days));
            $error !== null ? $this->addFlash('error', $error) : $this->addFlash('success', 'Décision appliquée et journalisée.');
        }

        return $this->redirectToRoute('admin_reports');
    }
}
