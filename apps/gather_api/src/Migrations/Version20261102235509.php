<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102235509 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE message ADD attachment_name VARCHAR(255) DEFAULT NULL, ADD attachment_mime_type VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE support_message CHANGE sender_id sender_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE support_ticket ADD guest_email VARCHAR(180) DEFAULT NULL, ADD guest_name VARCHAR(120) DEFAULT NULL, ADD page_url VARCHAR(2048) DEFAULT NULL, CHANGE user_id user_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD is_excluded_from_search TINYINT NOT NULL');

        // Jusqu'ici, un compte privé était absent de la recherche : personne ne devient trouvable sans l'avoir choisi
        $this->addSql('UPDATE users SET is_excluded_from_search = is_private');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE message DROP attachment_name, DROP attachment_mime_type');
        $this->addSql('ALTER TABLE support_message CHANGE sender_id sender_id BINARY(16) NOT NULL');
        $this->addSql('ALTER TABLE support_ticket DROP guest_email, DROP guest_name, DROP page_url, CHANGE user_id user_id BINARY(16) NOT NULL');
        $this->addSql('ALTER TABLE users DROP is_excluded_from_search');
    }
}
