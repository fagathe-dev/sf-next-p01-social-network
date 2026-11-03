<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102000518 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bookmark (id BINARY(16) NOT NULL, collection_name VARCHAR(100) DEFAULT NULL, is_public_collection TINYINT NOT NULL, created_at DATETIME NOT NULL, post_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_bookmark (post_id, user_id), INDEX IDX_DA62921D4B89032C (post_id), INDEX IDX_DA62921DA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE comment (id BINARY(16) NOT NULL, status VARCHAR(20) NOT NULL, reports_count INT NOT NULL, created_at DATETIME NOT NULL, content LONGTEXT NOT NULL, post_id BINARY(16) NOT NULL, author_id BINARY(16) NOT NULL, parent_comment_id BINARY(16) DEFAULT NULL, INDEX idx_comment_post (post_id, status, created_at), INDEX IDX_9474526C4B89032C (post_id), INDEX IDX_9474526CF675F31B (author_id), INDEX IDX_9474526CBF2AF943 (parent_comment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE conversation (id BINARY(16) NOT NULL, updated_at DATETIME NOT NULL, title VARCHAR(120) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE conversation_participant (id BINARY(16) NOT NULL, last_read_at DATETIME DEFAULT NULL, unread_count INT NOT NULL, conversation_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_conversation_participant (conversation_id, user_id), INDEX IDX_398016619AC0396 (conversation_id), INDEX IDX_39801661A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE friendship (id BINARY(16) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, requester_id BINARY(16) NOT NULL, addressee_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_friendship_pair (requester_id, addressee_id), INDEX IDX_7234A45FED442CF4 (requester_id), INDEX IDX_7234A45F2261B4C3 (addressee_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE group_member (id BINARY(16) NOT NULL, joined_at DATETIME DEFAULT NULL, role VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, group_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_group_member (group_id, user_id), INDEX IDX_A36222A8FE54D947 (group_id), INDEX IDX_A36222A8A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE link_preview (id BINARY(16) NOT NULL, url_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, url VARCHAR(2048) NOT NULL, domain VARCHAR(255) NOT NULL, og_type VARCHAR(60) DEFAULT NULL, title VARCHAR(255) DEFAULT NULL, description VARCHAR(1024) DEFAULT NULL, image_url VARCHAR(2048) DEFAULT NULL, site_name VARCHAR(255) DEFAULT NULL, UNIQUE INDEX uniq_link_preview_hash (url_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE message (id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, content LONGTEXT NOT NULL, attachment_path VARCHAR(512) DEFAULT NULL, conversation_id BINARY(16) NOT NULL, sender_id BINARY(16) NOT NULL, INDEX idx_message_conversation (conversation_id, created_at), INDEX IDX_B6BD307F9AC0396 (conversation_id), INDEX IDX_B6BD307FF624B39D (sender_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE moderation_log (id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, action_type VARCHAR(30) NOT NULL, reason VARCHAR(1000) NOT NULL, duration_days INT DEFAULT NULL, moderator_id BINARY(16) NOT NULL, target_user_id BINARY(16) DEFAULT NULL, INDEX idx_moderation_log_date (created_at), INDEX IDX_7AE8684DD0AFA354 (moderator_id), INDEX IDX_7AE8684D6C066AFE (target_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE post (id BINARY(16) NOT NULL, title VARCHAR(255) DEFAULT NULL, status VARCHAR(20) NOT NULL, location_name VARCHAR(255) DEFAULT NULL, views_count INT NOT NULL, likes_count INT NOT NULL, comments_count INT NOT NULL, bookmarks_count INT NOT NULL, reports_count INT NOT NULL, popularity_score DOUBLE PRECISION NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, slug VARCHAR(255) NOT NULL, content LONGTEXT NOT NULL, visibility VARCHAR(20) NOT NULL, group_id BINARY(16) DEFAULT NULL, link_preview_id BINARY(16) DEFAULT NULL, author_id BINARY(16) NOT NULL, FULLTEXT INDEX idx_post_search (title, content, location_name), INDEX idx_post_popular (status, visibility, created_at, popularity_score), INDEX IDX_5A8A6C8DFE54D947 (group_id), INDEX IDX_5A8A6C8D9A6EAF2E (link_preview_id), INDEX IDX_5A8A6C8DF675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE post_tag (post_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_5ACE3AF04B89032C (post_id), INDEX IDX_5ACE3AF0BAD26311 (tag_id), PRIMARY KEY (post_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE post_attachment (id BINARY(16) NOT NULL, type VARCHAR(20) NOT NULL, file_path VARCHAR(512) NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(120) NOT NULL, file_size INT NOT NULL, position INT NOT NULL, thumbnail_path VARCHAR(512) DEFAULT NULL, metadata JSON NOT NULL, post_id BINARY(16) NOT NULL, INDEX IDX_5A27D07A4B89032C (post_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE post_like (id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, post_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_post_like (post_id, user_id), INDEX IDX_653627B84B89032C (post_id), INDEX IDX_653627B8A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE post_view (id BINARY(16) NOT NULL, viewed_at DATETIME NOT NULL, post_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_post_view (post_id, user_id), INDEX IDX_37A8CC854B89032C (post_id), INDEX IDX_37A8CC85A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE refresh_tokens (refresh_token VARCHAR(128) NOT NULL, username VARCHAR(255) NOT NULL, valid DATETIME NOT NULL, family VARCHAR(32) DEFAULT NULL, family_valid DATETIME DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, user_agent VARCHAR(255) DEFAULT NULL, created_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_9BACE7E1C74F2195 (refresh_token), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE report (id BINARY(16) NOT NULL, target_id BINARY(16) NOT NULL, target_type VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, reason VARCHAR(30) NOT NULL, details LONGTEXT DEFAULT NULL, reported_post_id BINARY(16) DEFAULT NULL, reported_comment_id BINARY(16) DEFAULT NULL, reported_user_id BINARY(16) DEFAULT NULL, resolved_by_id BINARY(16) DEFAULT NULL, reporter_id BINARY(16) NOT NULL, INDEX idx_report_queue (status, target_type, created_at), UNIQUE INDEX uniq_report_target (reporter_id, target_type, target_id), INDEX IDX_C42F7784EC0086D7 (reported_post_id), INDEX IDX_C42F77849368B60F (reported_comment_id), INDEX IDX_C42F7784E7566E (reported_user_id), INDEX IDX_C42F77846713A32B (resolved_by_id), INDEX IDX_C42F7784E1CFE6F5 (reporter_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE social_group (id BINARY(16) NOT NULL, description LONGTEXT DEFAULT NULL, theme_palette VARCHAR(20) NOT NULL, icon_name VARCHAR(60) DEFAULT NULL, visibility VARCHAR(20) NOT NULL, members_count INT NOT NULL, created_at DATETIME NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(140) NOT NULL, owner_id BINARY(16) NOT NULL, FULLTEXT INDEX idx_group_search (name, description), INDEX idx_group_vis (visibility, slug), UNIQUE INDEX uniq_group_slug (slug), INDEX IDX_2750F0577E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE support_message (id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, content LONGTEXT NOT NULL, is_internal_note TINYINT NOT NULL, attachment_path VARCHAR(512) DEFAULT NULL, ticket_id BINARY(16) NOT NULL, sender_id BINARY(16) NOT NULL, INDEX IDX_B883883700047D2 (ticket_id), INDEX IDX_B883883F624B39D (sender_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE support_ticket (id BINARY(16) NOT NULL, status VARCHAR(20) NOT NULL, priority VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, reference VARCHAR(20) NOT NULL, category VARCHAR(30) NOT NULL, subject VARCHAR(180) NOT NULL, assigned_to_id BINARY(16) DEFAULT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_ticket_reference (reference), INDEX IDX_1F5A4D53F4BD7827 (assigned_to_id), INDEX IDX_1F5A4D53A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tag (id BINARY(16) NOT NULL, usage_count_7d INT NOT NULL, trending_score DOUBLE PRECISION NOT NULL, updated_at DATETIME NOT NULL, name VARCHAR(100) NOT NULL, slug VARCHAR(120) NOT NULL, INDEX idx_tag_trending (name, trending_score), UNIQUE INDEX uniq_tag_name (name), UNIQUE INDEX uniq_tag_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_requests (id BINARY(16) NOT NULL, code VARCHAR(20) DEFAULT NULL, token VARCHAR(128) DEFAULT NULL, type VARCHAR(40) NOT NULL, payload JSON DEFAULT NULL, is_used TINYINT DEFAULT 0 NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, user_id BINARY(16) NOT NULL, INDEX idx_user_request_token (token), INDEX idx_user_request_code (code), INDEX IDX_9B083251A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE users (id BINARY(16) NOT NULL, email VARCHAR(180) NOT NULL, role VARCHAR(50) NOT NULL, password VARCHAR(255) NOT NULL, username VARCHAR(90) NOT NULL, firstname VARCHAR(80) DEFAULT NULL, lastname VARCHAR(80) DEFAULT NULL, avatar VARCHAR(1024) DEFAULT NULL, banner VARCHAR(1024) DEFAULT NULL, bio LONGTEXT DEFAULT NULL, birth_date DATE DEFAULT NULL, is_private TINYINT NOT NULL, status VARCHAR(20) NOT NULL, sanction_expires_at DATETIME DEFAULT NULL, warnings_count INT NOT NULL, is_verified TINYINT DEFAULT NULL, email_verified_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, settings JSON DEFAULT NULL, invited_by_id BINARY(16) DEFAULT NULL, FULLTEXT INDEX idx_user_search (username, firstname, lastname, bio), INDEX idx_user_privacy (is_private, status), UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email), UNIQUE INDEX UNIQ_IDENTIFIER_USERNAME (username), INDEX IDX_1483A5E9A7B4A7E3 (invited_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bookmark ADD CONSTRAINT FK_DA62921D4B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bookmark ADD CONSTRAINT FK_DA62921DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C4B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526CF675F31B FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526CBF2AF943 FOREIGN KEY (parent_comment_id) REFERENCES comment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversation_participant ADD CONSTRAINT FK_398016619AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversation_participant ADD CONSTRAINT FK_39801661A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE friendship ADD CONSTRAINT FK_7234A45FED442CF4 FOREIGN KEY (requester_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE friendship ADD CONSTRAINT FK_7234A45F2261B4C3 FOREIGN KEY (addressee_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE group_member ADD CONSTRAINT FK_A36222A8FE54D947 FOREIGN KEY (group_id) REFERENCES social_group (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE group_member ADD CONSTRAINT FK_A36222A8A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307F9AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307FF624B39D FOREIGN KEY (sender_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE moderation_log ADD CONSTRAINT FK_7AE8684DD0AFA354 FOREIGN KEY (moderator_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE moderation_log ADD CONSTRAINT FK_7AE8684D6C066AFE FOREIGN KEY (target_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE post ADD CONSTRAINT FK_5A8A6C8DFE54D947 FOREIGN KEY (group_id) REFERENCES social_group (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE post ADD CONSTRAINT FK_5A8A6C8D9A6EAF2E FOREIGN KEY (link_preview_id) REFERENCES link_preview (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE post ADD CONSTRAINT FK_5A8A6C8DF675F31B FOREIGN KEY (author_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE post_tag ADD CONSTRAINT FK_5ACE3AF04B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE post_tag ADD CONSTRAINT FK_5ACE3AF0BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE post_attachment ADD CONSTRAINT FK_5A27D07A4B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE post_like ADD CONSTRAINT FK_653627B84B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE post_like ADD CONSTRAINT FK_653627B8A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE post_view ADD CONSTRAINT FK_37A8CC854B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE post_view ADD CONSTRAINT FK_37A8CC85A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE report ADD CONSTRAINT FK_C42F7784EC0086D7 FOREIGN KEY (reported_post_id) REFERENCES post (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE report ADD CONSTRAINT FK_C42F77849368B60F FOREIGN KEY (reported_comment_id) REFERENCES comment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE report ADD CONSTRAINT FK_C42F7784E7566E FOREIGN KEY (reported_user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE report ADD CONSTRAINT FK_C42F77846713A32B FOREIGN KEY (resolved_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE report ADD CONSTRAINT FK_C42F7784E1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE social_group ADD CONSTRAINT FK_2750F0577E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE support_message ADD CONSTRAINT FK_B883883700047D2 FOREIGN KEY (ticket_id) REFERENCES support_ticket (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE support_message ADD CONSTRAINT FK_B883883F624B39D FOREIGN KEY (sender_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE support_ticket ADD CONSTRAINT FK_1F5A4D53F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE support_ticket ADD CONSTRAINT FK_1F5A4D53A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_requests ADD CONSTRAINT FK_9B083251A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9A7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bookmark DROP FOREIGN KEY FK_DA62921D4B89032C');
        $this->addSql('ALTER TABLE bookmark DROP FOREIGN KEY FK_DA62921DA76ED395');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526C4B89032C');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526CF675F31B');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526CBF2AF943');
        $this->addSql('ALTER TABLE conversation_participant DROP FOREIGN KEY FK_398016619AC0396');
        $this->addSql('ALTER TABLE conversation_participant DROP FOREIGN KEY FK_39801661A76ED395');
        $this->addSql('ALTER TABLE friendship DROP FOREIGN KEY FK_7234A45FED442CF4');
        $this->addSql('ALTER TABLE friendship DROP FOREIGN KEY FK_7234A45F2261B4C3');
        $this->addSql('ALTER TABLE group_member DROP FOREIGN KEY FK_A36222A8FE54D947');
        $this->addSql('ALTER TABLE group_member DROP FOREIGN KEY FK_A36222A8A76ED395');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307F9AC0396');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307FF624B39D');
        $this->addSql('ALTER TABLE moderation_log DROP FOREIGN KEY FK_7AE8684DD0AFA354');
        $this->addSql('ALTER TABLE moderation_log DROP FOREIGN KEY FK_7AE8684D6C066AFE');
        $this->addSql('ALTER TABLE post DROP FOREIGN KEY FK_5A8A6C8DFE54D947');
        $this->addSql('ALTER TABLE post DROP FOREIGN KEY FK_5A8A6C8D9A6EAF2E');
        $this->addSql('ALTER TABLE post DROP FOREIGN KEY FK_5A8A6C8DF675F31B');
        $this->addSql('ALTER TABLE post_tag DROP FOREIGN KEY FK_5ACE3AF04B89032C');
        $this->addSql('ALTER TABLE post_tag DROP FOREIGN KEY FK_5ACE3AF0BAD26311');
        $this->addSql('ALTER TABLE post_attachment DROP FOREIGN KEY FK_5A27D07A4B89032C');
        $this->addSql('ALTER TABLE post_like DROP FOREIGN KEY FK_653627B84B89032C');
        $this->addSql('ALTER TABLE post_like DROP FOREIGN KEY FK_653627B8A76ED395');
        $this->addSql('ALTER TABLE post_view DROP FOREIGN KEY FK_37A8CC854B89032C');
        $this->addSql('ALTER TABLE post_view DROP FOREIGN KEY FK_37A8CC85A76ED395');
        $this->addSql('ALTER TABLE report DROP FOREIGN KEY FK_C42F7784EC0086D7');
        $this->addSql('ALTER TABLE report DROP FOREIGN KEY FK_C42F77849368B60F');
        $this->addSql('ALTER TABLE report DROP FOREIGN KEY FK_C42F7784E7566E');
        $this->addSql('ALTER TABLE report DROP FOREIGN KEY FK_C42F77846713A32B');
        $this->addSql('ALTER TABLE report DROP FOREIGN KEY FK_C42F7784E1CFE6F5');
        $this->addSql('ALTER TABLE social_group DROP FOREIGN KEY FK_2750F0577E3C61F9');
        $this->addSql('ALTER TABLE support_message DROP FOREIGN KEY FK_B883883700047D2');
        $this->addSql('ALTER TABLE support_message DROP FOREIGN KEY FK_B883883F624B39D');
        $this->addSql('ALTER TABLE support_ticket DROP FOREIGN KEY FK_1F5A4D53F4BD7827');
        $this->addSql('ALTER TABLE support_ticket DROP FOREIGN KEY FK_1F5A4D53A76ED395');
        $this->addSql('ALTER TABLE user_requests DROP FOREIGN KEY FK_9B083251A76ED395');
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_1483A5E9A7B4A7E3');
        $this->addSql('DROP TABLE bookmark');
        $this->addSql('DROP TABLE comment');
        $this->addSql('DROP TABLE conversation');
        $this->addSql('DROP TABLE conversation_participant');
        $this->addSql('DROP TABLE friendship');
        $this->addSql('DROP TABLE group_member');
        $this->addSql('DROP TABLE link_preview');
        $this->addSql('DROP TABLE message');
        $this->addSql('DROP TABLE moderation_log');
        $this->addSql('DROP TABLE post');
        $this->addSql('DROP TABLE post_tag');
        $this->addSql('DROP TABLE post_attachment');
        $this->addSql('DROP TABLE post_like');
        $this->addSql('DROP TABLE post_view');
        $this->addSql('DROP TABLE refresh_tokens');
        $this->addSql('DROP TABLE report');
        $this->addSql('DROP TABLE social_group');
        $this->addSql('DROP TABLE support_message');
        $this->addSql('DROP TABLE support_ticket');
        $this->addSql('DROP TABLE tag');
        $this->addSql('DROP TABLE user_requests');
        $this->addSql('DROP TABLE users');
    }
}
