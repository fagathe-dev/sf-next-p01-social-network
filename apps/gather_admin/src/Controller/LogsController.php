<?php

declare(strict_types=1);

namespace App\Controller;

use Fagathe\GatherCore\Enum\Moderation\ModerationActionType;
use Fagathe\GatherCore\Repository\ModerationLogRepository;
use Fagathe\GatherCore\Service\LogViewerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/logs', name: 'admin_logs_')]
final class LogsController extends AbstractController
{
    #[Route('/moderation', name: 'moderation', methods: ['GET'])]
    public function moderation(Request $request, ModerationLogRepository $logs): Response
    {
        $query = $request->query;

        return $this->render('logs/moderation.html.twig', [
            'result' => $logs->search(
                $query->getString('moderator') ?: null,
                $query->getString('target') ?: null,
                ModerationActionType::tryFrom($query->getString('action')),
                self::parseDate($query->getString('from')),
                self::parseDate($query->getString('to'), endOfDay: true),
                max(1, $query->getInt('page', 1)),
            ),
            'filters' => $query->all(),
            'actions' => ModerationActionType::cases(),
        ]);
    }

    #[Route('/system', name: 'system', methods: ['GET'])]
    public function system(Request $request, LogViewerService $viewer): Response
    {
        $date = $request->query->getString('date');
        $file = $request->query->getString('file');
        $level = $request->query->getString('level');
        $search = mb_strtolower($request->query->getString('q'));

        $entries = [];
        if ($date !== '' && $file !== '') {
            $result = $viewer->getFile($date, $file);
            $entries = is_array($result->data) && array_is_list($result->data) ? $result->data : [];
            $entries = array_values(array_filter($entries, static function (array $entry) use ($level, $search): bool {
                if ($level !== '' && ($entry['level'] ?? '') !== $level) {
                    return false;
                }

                return $search === '' || str_contains(mb_strtolower((string) json_encode($entry, JSON_UNESCAPED_UNICODE)), $search);
            }));
        }

        return $this->render('logs/system.html.twig', [
            'tree' => $viewer->getTree()->data,
            'date' => $date,
            'file' => $file,
            'level' => $level,
            'search' => $request->query->getString('q'),
            'entries' => array_slice($entries, 0, 200),
        ]);
    }

    private static function parseDate(string $value, bool $endOfDay = false): ?\DateTimeImmutable
    {
        $date = $value !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false) {
            return null;
        }

        return $endOfDay ? $date->setTime(23, 59, 59) : $date;
    }
}
