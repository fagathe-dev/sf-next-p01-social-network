<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Doctrine\ORM\EntityManagerInterface;
use Fagathe\CorePhp\Enum\LoggerLevelEnum;
use Fagathe\CorePhp\Trait\LoggerTrait;
use Fagathe\GatherCore\Entity\LinkPreview;
use Fagathe\GatherCore\Repository\LinkPreviewRepository;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Lit les balises OpenGraph d'une URL fournie par un utilisateur.
 *
 * L'URL étant choisie par le client, le serveur ne doit jamais pouvoir être amené à joindre son propre
 * réseau (SSRF). Chaque saut est vérifié, et l'IP contrôlée est imposée au client HTTP : un nom de domaine
 * ne peut pas changer de résolution entre le contrôle et la connexion (DNS rebinding).
 */
final class LinkPreviewExtractorService
{
    use LoggerTrait;

    private const MAX_REDIRECTS = 3;
    private const MAX_BYTES = 2097152;
    private const TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LinkPreviewRepository $previews,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @throws LinkPreviewException si l'URL est refusée ou inexploitable
     */
    public function extract(string $url): LinkPreview
    {
        $url = trim($url);
        $cached = $this->previews->findByUrl($url);
        if ($cached !== null) {
            return $cached;
        }

        [$html, $finalUrl] = $this->download($url);
        $preview = $this->parse($html, $url, $finalUrl);

        $this->em->persist($preview);
        $this->em->flush();

        return $preview;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function download(string $url): array
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            [$host, $ip] = $this->assertSafeUrl($current);

            try {
                $response = $this->httpClient->request('GET', $current, [
                    'max_redirects' => 0,
                    'timeout' => self::TIMEOUT_SECONDS,
                    'max_duration' => self::TIMEOUT_SECONDS * 2,
                    'resolve' => [$host => $ip],
                    'headers' => ['User-Agent' => 'GatherBot/1.0 (+link preview)', 'Accept' => 'text/html'],
                ]);

                $status = $response->getStatusCode();
                if ($status >= 300 && $status < 400) {
                    $location = $response->getHeaders(false)['location'][0] ?? null;
                    if ($location === null) {
                        break;
                    }
                    $current = self::absoluteUrl($location, $current);
                    continue;
                }

                if ($status !== 200 || !str_contains($response->getHeaders(false)['content-type'][0] ?? '', 'html')) {
                    throw new LinkPreviewException('Cette page ne peut pas être prévisualisée.');
                }

                $body = '';
                foreach ($this->httpClient->stream($response) as $chunk) {
                    $body .= $chunk->getContent();
                    if (strlen($body) > self::MAX_BYTES) {
                        $response->cancel();
                        break;
                    }
                }

                return [$body, $current];
            } catch (LinkPreviewException $e) {
                throw $e;
            } catch (Throwable $e) {
                $this->generateLog(
                    LoggerLevelEnum::Warning,
                    ['message' => 'Échec du scraping OpenGraph', 'data' => ['host' => $host], 'exception' => $e->getMessage()],
                    ['action' => 'link_preview.scraping_error']
                );

                throw new LinkPreviewException('Impossible de joindre cette page.');
            }
        }

        throw new LinkPreviewException('Trop de redirections.');
    }

    /**
     * @return array{0: string, 1: string} hôte et IP contrôlée
     */
    public function assertSafeUrl(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new LinkPreviewException('Adresse invalide.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) {
            throw new LinkPreviewException('Adresse refusée.');
        }

        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : self::resolve($host);
        if ($addresses === []) {
            throw new LinkPreviewException('Domaine introuvable.');
        }

        // Une seule adresse privée suffit à refuser : le système pourrait choisir celle-là
        foreach ($addresses as $address) {
            if (!self::isPublicIp($address)) {
                throw new LinkPreviewException('Adresse refusée.');
            }
        }

        return [$host, $addresses[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        // ::ffff:10.0.0.1 désigne une IPv4 : c'est elle qu'il faut contrôler
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m) === 1) {
            $ip = $m[1];
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }

    private function parse(string $html, string $url, string $finalUrl): LinkPreview
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent($html);

        $meta = static function (string $selector) use ($crawler): ?string {
            $node = $crawler->filter($selector);
            $value = $node->count() > 0 ? trim((string) $node->first()->attr('content')) : '';

            return $value !== '' ? $value : null;
        };

        $titleNode = $crawler->filter('title');
        $title = $meta('meta[property="og:title"]') ?? ($titleNode->count() > 0 ? trim($titleNode->first()->text('')) : null);
        if ($title === null || $title === '') {
            throw new LinkPreviewException('Aucune information à prévisualiser sur cette page.');
        }

        $image = $meta('meta[property="og:image"]');
        if ($image !== null) {
            $image = self::absoluteUrl($image, $finalUrl);
            if (!str_starts_with($image, 'https://') && !str_starts_with($image, 'http://')) {
                $image = null;
            }
        }

        return new LinkPreview(
            $url,
            (string) parse_url($finalUrl, PHP_URL_HOST),
            mb_substr((string) $meta('meta[property="og:type"]'), 0, 60) ?: null,
            mb_substr($title, 0, 255),
            mb_substr((string) ($meta('meta[property="og:description"]') ?? $meta('meta[name="description"]')), 0, 1024) ?: null,
            $image !== null ? mb_substr($image, 0, 2048) : null,
            mb_substr((string) $meta('meta[property="og:site_name"]'), 0, 255) ?: null,
        );
    }

    private static function absoluteUrl(string $location, string $base): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = sprintf('%s://%s', $parts['scheme'] ?? 'https', $parts['host'] ?? '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        return $origin . rtrim(dirname($parts['path'] ?? '/'), '/\\') . '/' . $location;
    }
}
