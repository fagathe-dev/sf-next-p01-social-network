<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Détection des #hashtags et @mentions, génération des slugs.
 */
final class ContentParser
{
    private const HASHTAG = '/(?<![\p{L}\p{N}_&])#([\p{L}\p{N}_]{2,60})/u';
    private const MENTION = '/(?<![\p{L}\p{N}_@.])@([a-zA-Z0-9_.-]{3,90})/u';

    /** @return list<string> sans doublon, en minuscules */
    public function hashtags(string $content): array
    {
        preg_match_all(self::HASHTAG, $content, $matches);

        return array_values(array_unique(array_map(static fn(string $tag) => mb_strtolower($tag), $matches[1])));
    }

    /** @return list<string> sans doublon */
    public function mentions(string $content): array
    {
        preg_match_all(self::MENTION, $content, $matches);

        return array_values(array_unique($matches[1]));
    }

    public function slug(string $text, string $fallback = 'publication', int $maxWords = 8): string
    {
        $text = preg_replace('/[#@]/', '', $text) ?? '';
        $words = array_slice(preg_split('/\s+/u', trim($text), flags: PREG_SPLIT_NO_EMPTY) ?: [], 0, $maxWords);
        $slug = (new AsciiSlugger('fr'))->slug(implode(' ', $words))->lower()->toString();
        $slug = mb_substr($slug, 0, 200);

        return $slug !== '' ? rtrim($slug, '-') : $fallback;
    }
}
