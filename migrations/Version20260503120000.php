<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * NIP-30 custom emoji catalog merged at prewarm (kind 0 + NIP-51 kind 10030 + NIP-38 kind 30315).
 */
final class Version20260503120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'event.nip30_custom_emoji JSON for kind-0 profile rows (NIP-30 merged emoji catalog)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD nip30_custom_emoji JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP nip30_custom_emoji');
    }
}
