<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260423120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Featured authors for site NIP-05 (category authors)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE featured_author (id INT AUTO_INCREMENT NOT NULL, pubkey_hex VARCHAR(64) NOT NULL, local_part VARCHAR(100) NOT NULL, is_listed TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_8EED8C6CE479AD9 (pubkey_hex), UNIQUE INDEX UNIQ_8EED8C6CEEEB401 (local_part), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE featured_author');
    }
}
