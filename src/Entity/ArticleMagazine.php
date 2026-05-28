<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArticleMagazineRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Links a global {@see Article} row to a magazine tenant that ingested or references it.
 */
#[ORM\Entity(repositoryClass: ArticleMagazineRepository::class)]
#[ORM\Table(name: 'article_magazine')]
class ArticleMagazine
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $magazineSlug = '';

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Article::class)]
    #[ORM\JoinColumn(name: 'article_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Article $article;

    public function __construct(string $magazineSlug, Article $article)
    {
        $this->magazineSlug = $magazineSlug;
        $this->article = $article;
    }

    public function getMagazineSlug(): string
    {
        return $this->magazineSlug;
    }

    public function getArticle(): Article
    {
        return $this->article;
    }
}
