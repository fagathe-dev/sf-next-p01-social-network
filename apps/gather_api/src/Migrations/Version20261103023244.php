<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261103023244 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE comment_like (id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, comment_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, INDEX idx_comment_like_user (user_id, created_at), UNIQUE INDEX uniq_comment_like (comment_id, user_id), INDEX IDX_8A55E25FF8697D13 (comment_id), INDEX IDX_8A55E25FA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification (id BINARY(16) NOT NULL, actors_count INT NOT NULL, read_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, type VARCHAR(40) NOT NULL, target_type VARCHAR(20) NOT NULL, target_id VARCHAR(36) NOT NULL, last_actor_id BINARY(16) DEFAULT NULL, recipient_id BINARY(16) NOT NULL, INDEX idx_notification_recipient (recipient_id, read_at, created_at), INDEX idx_notification_recent (recipient_id, updated_at), INDEX IDX_BF5476CA68BBB7D4 (last_actor_id), INDEX IDX_BF5476CAE92F8F78 (recipient_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE comment_like ADD CONSTRAINT FK_8A55E25FF8697D13 FOREIGN KEY (comment_id) REFERENCES comment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment_like ADD CONSTRAINT FK_8A55E25FA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA68BBB7D4 FOREIGN KEY (last_actor_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAE92F8F78 FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_bookmark_user ON bookmark (user_id, created_at)');
        $this->addSql('ALTER TABLE comment ADD likes_count INT NOT NULL, ADD edited_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_comment_author ON comment (author_id, created_at)');
        $this->addSql('ALTER TABLE post ADD is_repost TINYINT NOT NULL, ADD reposts_count INT NOT NULL, ADD edited_at DATETIME DEFAULT NULL, ADD original_post_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE post ADD CONSTRAINT FK_5A8A6C8DCD09ADDB FOREIGN KEY (original_post_id) REFERENCES post (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_5A8A6C8DCD09ADDB ON post (original_post_id)');
        $this->addSql('CREATE INDEX idx_post_like_user ON post_like (user_id, created_at)');
        $this->addSql('CREATE INDEX idx_post_view_user ON post_view (user_id, viewed_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE comment_like DROP FOREIGN KEY FK_8A55E25FF8697D13');
        $this->addSql('ALTER TABLE comment_like DROP FOREIGN KEY FK_8A55E25FA76ED395');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA68BBB7D4');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CAE92F8F78');
        $this->addSql('DROP TABLE comment_like');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP INDEX idx_bookmark_user ON bookmark');
        $this->addSql('DROP INDEX idx_comment_author ON comment');
        $this->addSql('ALTER TABLE comment DROP likes_count, DROP edited_at');
        $this->addSql('ALTER TABLE post DROP FOREIGN KEY FK_5A8A6C8DCD09ADDB');
        $this->addSql('DROP INDEX IDX_5A8A6C8DCD09ADDB ON post');
        $this->addSql('ALTER TABLE post DROP is_repost, DROP reposts_count, DROP edited_at, DROP original_post_id');
        $this->addSql('DROP INDEX idx_post_like_user ON post_like');
        $this->addSql('DROP INDEX idx_post_view_user ON post_view');
    }
}
