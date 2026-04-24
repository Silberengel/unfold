<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Core Nostr events (30040 indices, kind-0 profiles) live in `event` with a stable {@see Event::getCoreRowKey()}.
 */
final class Version20260424130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'event.core_row_key + event.storage_role for DB-backed magazine indices and profiles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD core_row_key VARCHAR(255) DEFAULT NULL, ADD storage_role VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3BAE0AA7F6F0AF27 ON event (core_row_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_3BAE0AA7F6F0AF27 ON event');
        $this->addSql('ALTER TABLE event DROP core_row_key, DROP storage_role');
    }
}
