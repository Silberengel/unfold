<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Denormalize highlight-author kind-0 fields so in-article avatars do not depend on relay fetches at render time.
 */
final class Version20260529153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store highlight author display name and picture URL on article_highlight';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article_highlight ADD author_display_name VARCHAR(255) DEFAULT NULL, ADD author_picture_url VARCHAR(2048) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article_highlight DROP author_display_name, DROP author_picture_url');
    }
}
