<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Multi-tenant shared MySQL: magazine_slug scopes site data; article rows stay global with article_magazine links.
 */
final class Version20260528140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shared DB multi-tenancy: article_magazine, magazine_slug on featured_author and app_user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE article_magazine (magazine_slug VARCHAR(64) NOT NULL, article_id INT NOT NULL, INDEX IDX_ARTICLE_MAGAZINE_ARTICLE (article_id), PRIMARY KEY (magazine_slug, article_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE article_magazine ADD CONSTRAINT FK_ARTICLE_MAGAZINE_ARTICLE FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE featured_author ADD magazine_slug VARCHAR(64) NOT NULL DEFAULT \'legacy\'');
        $this->addSql('ALTER TABLE featured_author ALTER magazine_slug DROP DEFAULT');
        $this->addSql('DROP INDEX UNIQ_8EED8C6CE479AD9 ON featured_author');
        $this->addSql('DROP INDEX UNIQ_8EED8C6CEEEB401 ON featured_author');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FEATURED_AUTHOR_MAG_PUBKEY ON featured_author (magazine_slug, pubkey_hex)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FEATURED_AUTHOR_MAG_LOCAL ON featured_author (magazine_slug, local_part)');

        $this->addSql('ALTER TABLE app_user ADD magazine_slug VARCHAR(64) NOT NULL DEFAULT \'legacy\'');
        $this->addSql('ALTER TABLE app_user ALTER magazine_slug DROP DEFAULT');
        $this->addSql('DROP INDEX UNIQ_88BDF3E95FB8BABB ON app_user');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_APP_USER_MAG_NPUB ON app_user (magazine_slug, npub)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article_magazine DROP FOREIGN KEY FK_ARTICLE_MAGAZINE_ARTICLE');
        $this->addSql('DROP TABLE article_magazine');

        $this->addSql('DROP INDEX UNIQ_FEATURED_AUTHOR_MAG_PUBKEY ON featured_author');
        $this->addSql('DROP INDEX UNIQ_FEATURED_AUTHOR_MAG_LOCAL ON featured_author');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8EED8C6CE479AD9 ON featured_author (pubkey_hex)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8EED8C6CEEEB401 ON featured_author (local_part)');
        $this->addSql('ALTER TABLE featured_author DROP magazine_slug');

        $this->addSql('DROP INDEX UNIQ_APP_USER_MAG_NPUB ON app_user');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E95FB8BABB ON app_user (npub)');
        $this->addSql('ALTER TABLE app_user DROP magazine_slug');
    }
}
