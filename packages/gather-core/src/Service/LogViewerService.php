<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Fagathe\CorePhp\Http\ResponseTrait;
use Fagathe\CorePhp\Logger\Log;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Lecture des fichiers de log JSON générés par Fagathe\CorePhp\Logger.
 *
 * Fichiers physiques : <logs_dir>/<dossier>/<nom>-dd-mm-yyyy.json (ex: service/auth-service-27-10-2026.json).
 * Un fichier est identifié par sa date et son chemin « pointé » (ex: 27-10-2026 + service.auth-service).
 */
final class LogViewerService
{
    use ResponseTrait;

    private const DATE_PATTERN = '/-(\d{2}-\d{2}-\d{4})\.json$/';
    private const FILE_PATH_PATTERN = '/^[\w\-]+(\.[\w\-]+)*$/';

    private readonly Filesystem $filesystem;
    private readonly string $logsDir;

    public function __construct(
        #[Autowire('%core_php.logger.logs_dir%')]
        string $logsDir,
    ) {
        $this->filesystem = new Filesystem();
        $this->logsDir = rtrim($logsDir, '/\\');
    }

    /**
     * Arborescence des logs groupés par date (la plus récente en premier).
     */
    public function getTree(): object
    {
        $tree = [];

        foreach ($this->getLogStructure() as $date => $items) {
            $nodes = $this->buildNodes($items, '');
            $tree[] = [
                'date'  => (string) $date,
                'count' => $this->countFiles($nodes),
                'items' => $nodes,
            ];
        }

        return $this->sendJson($tree);
    }

    /**
     * Entrées d'un fichier de log, les plus récentes en premier.
     *
     * @param string $date     ex: 27-10-2026
     * @param string $filePath chemin pointé, ex: service.auth-service
     */
    public function getFile(string $date, string $filePath): object
    {
        $fullPath = $this->resolveFilePath($date, $filePath);
        if ($fullPath === null) {
            return $this->notFoundResponse('Fichier de log introuvable.');
        }

        $content = (string) file_get_contents($fullPath);
        if (trim($content) === '') {
            return $this->sendJson([]);
        }

        $logsData = json_decode($content, true);

        if (!is_array($logsData)) {
            // Fichiers en JSON Lines (une entrée JSON par ligne)
            $lines = file($fullPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $logsData = array_map(static fn(string $line): mixed => json_decode($line, true), $lines);
        }

        $logs = array_values(array_map(
            fn(array $logData): array => $this->normalizeLog(Log::fromArray($logData)),
            array_filter($logsData, 'is_array')
        ));

        usort($logs, static fn(array $a, array $b): int => strcmp((string) $b['timestamp'], (string) $a['timestamp']));

        return $this->sendJson($logs);
    }

    /**
     * @return array<string, array<int|string, mixed>> ['dd-mm-yyyy' => ['dossier' => ['fichier'], 'fichier']]
     */
    private function getLogStructure(): array
    {
        if (!$this->filesystem->exists($this->logsDir)) {
            return [];
        }

        $finder = (new Finder())->files()->in($this->logsDir)->name('*.json')->sortByName();
        $structure = [];

        foreach ($finder as $file) {
            $this->processFileIntoStructure($file, $structure);
        }

        uksort($structure, static function (string|int $a, string|int $b): int {
            $dateA = DateTimeImmutable::createFromFormat('d-m-Y', (string) $a);
            $dateB = DateTimeImmutable::createFromFormat('d-m-Y', (string) $b);

            return $dateB <=> $dateA;
        });

        return $structure;
    }

    /**
     * Insère un fichier dans la structure, quelle que soit la profondeur de sous-dossiers.
     *
     * @param array<string, array<int|string, mixed>> $structure
     */
    private function processFileIntoStructure(SplFileInfo $file, array &$structure): void
    {
        $relativePath = str_replace('\\', '/', $file->getRelativePathname());

        if (!preg_match(self::DATE_PATTERN, $relativePath, $matches)) {
            return;
        }

        $date = $matches[1];
        $parts = explode('/', str_replace('-' . $date . '.json', '', $relativePath));
        $filename = array_pop($parts);

        $structure[$date] ??= [];
        $current = &$structure[$date];

        foreach ($parts as $folder) {
            $current[$folder] ??= [];
            $current = &$current[$folder];
        }

        $current[] = $filename;
    }

    /**
     * Convertit la structure brute en nœuds typés (dossiers d'abord, puis fichiers).
     *
     * @param array<int|string, mixed> $items
     *
     * @return list<array<string, mixed>>
     */
    private function buildNodes(array $items, string $parentPath): array
    {
        $folders = [];
        $files = [];

        foreach ($items as $key => $value) {
            if (is_array($value)) {
                $path = $parentPath === '' ? (string) $key : $parentPath . '.' . $key;
                $folders[] = [
                    'type'     => 'folder',
                    'name'     => (string) $key,
                    'path'     => $path,
                    'children' => $this->buildNodes($value, $path),
                ];
            } else {
                $files[] = [
                    'type' => 'file',
                    'name' => (string) $value,
                    'path' => $parentPath === '' ? (string) $value : $parentPath . '.' . $value,
                ];
            }
        }

        usort($folders, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        usort($files, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [...$folders, ...$files];
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function countFiles(array $nodes): int
    {
        $count = 0;

        foreach ($nodes as $node) {
            $count += $node['type'] === 'folder' ? $this->countFiles($node['children']) : 1;
        }

        return $count;
    }

    /**
     * Construit le chemin physique en refusant toute sortie du dossier de logs.
     */
    private function resolveFilePath(string $date, string $filePath): ?string
    {
        if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $date) || !preg_match(self::FILE_PATH_PATTERN, $filePath)) {
            return null;
        }

        $fullPath = sprintf('%s/%s-%s.json', $this->logsDir, str_replace('.', '/', $filePath), $date);
        $realPath = realpath($fullPath);
        $realLogsDir = realpath($this->logsDir);

        if ($realPath === false || $realLogsDir === false || !str_starts_with($realPath, $realLogsDir . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realPath;
    }

    /**
     * @return array{id: string|int|null, level: string|null, timestamp: string|null, content: array<string, mixed>, context: array<string, mixed>}
     */
    private function normalizeLog(Log $log): array
    {
        return [
            'id'        => $log->getId(),
            'level'     => $log->getLevel()?->value,
            'timestamp' => $log->getTimestamp()?->format(DateTimeInterface::ATOM),
            'content'   => $log->getContents() ?? [],
            'context'   => $log->getContexts() ?? [],
        ];
    }
}
