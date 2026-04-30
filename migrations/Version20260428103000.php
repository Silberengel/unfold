<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove deprecated home-curation kind-1 cache rows (no longer written or read).
 */
final class Version20260428103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete event rows with storage_role magazine_curation_kind1_ref (curation strip is 30023-only)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM event WHERE storage_role = 'magazine_curation_kind1_ref'");
    }

    public function down(Schema $schema): void
    {
        // Irreversible data purge; rows were ephemeral cache copies of relay notes.
    }
}
