<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Kind 9802 (highlights) for long-form articles — stored locally; not part of the relay-only comment thread cache.
 */
final class Version20260425200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table article_highlight for Nostr kind-9802 highlights (linked to article rows)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE article_highlight (id INT AUTO_INCREMENT NOT NULL, event_id VARCHAR(64) NOT NULL, article_id INT NOT NULL, author_pubkey VARCHAR(64) NOT NULL, content LONGTEXT NOT NULL, tags JSON NOT NULL, event_created_at BIGINT NOT NULL, quote_excerpt VARCHAR(512) DEFAULT NULL, INDEX IDX_8F7E8A72946689E (article_id), INDEX IDX_highlight_event_created (event_created_at), UNIQUE INDEX UNIQ_8F7E8A7271F7E88B (event_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE article_highlight ADD CONSTRAINT FK_8F7E8A72946689E FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article_highlight DROP FOREIGN KEY FK_8F7E8A72946689E');
        $this->addSql('DROP TABLE article_highlight');
    }
}
