<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260527110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add wiki_kinds column to article table for NIP-54 kind 30817 wiki pages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article ADD wiki_kinds JSON DEFAULT NULL COMMENT \'NIP-54 wiki: k-tag kind values (null = not a wiki page)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article DROP COLUMN wiki_kinds');
    }
}
