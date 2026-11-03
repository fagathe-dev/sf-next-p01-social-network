<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Scores de la section 4 : recalculés périodiquement, jamais à la lecture.
 */
final class PopularityCalculatorService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /** Score = (V × 0,1 + L + C × 3 + R × 4) / (heures + 2)^1,5 sur 7 jours glissants. */
    public function recalculatePosts(): int
    {
        return (int) $this->em->getConnection()->executeStatement(<<<'SQL'
            UPDATE post
            SET popularity_score = ((views_count * 0.1) + (likes_count * 1) + (comments_count * 3) + (reposts_count * 4))
                / POW(TIMESTAMPDIFF(HOUR, created_at, NOW()) + 2, 1.5)
            WHERE created_at >= NOW() - INTERVAL 7 DAY
              AND status = 'published'
            SQL);
    }

    /**
     * Score = P24h × 5 + P7j × 1 + auteurs uniques × 3 + (likes + commentaires) × 0,2.
     * Seuls les posts lisibles par tous comptent : un tag ne doit rien révéler d'un espace privé.
     */
    public function recalculateTags(): int
    {
        return (int) $this->em->getConnection()->executeStatement(<<<'SQL'
            UPDATE tag t
            LEFT JOIN (
                SELECT pt.tag_id,
                       SUM(p.created_at >= NOW() - INTERVAL 1 DAY) AS posts_24h,
                       SUM(p.created_at < NOW() - INTERVAL 1 DAY) AS posts_7d,
                       COUNT(DISTINCT p.author_id) AS authors,
                       SUM(p.likes_count + p.comments_count) AS engagement,
                       COUNT(*) AS total
                FROM post_tag pt
                INNER JOIN post p ON p.id = pt.post_id
                LEFT JOIN social_group g ON g.id = p.group_id
                INNER JOIN users u ON u.id = p.author_id
                WHERE p.created_at >= NOW() - INTERVAL 7 DAY
                  AND p.status = 'published'
                  AND ((p.group_id IS NULL AND p.visibility = 'public' AND u.is_private = 0) OR g.visibility = 'public')
                GROUP BY pt.tag_id
            ) stats ON stats.tag_id = t.id
            SET t.usage_count_7d = COALESCE(stats.total, 0),
                t.trending_score = COALESCE((stats.posts_24h * 5) + stats.posts_7d + (stats.authors * 3) + (stats.engagement * 0.2), 0),
                t.updated_at = NOW()
            SQL);
    }
}
