<?php

namespace App\Twig\Components;
use App\Entity\Event as EventEntity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class IndexTabs
{
    use DefaultActionTrait;

    private $index;

    #[LiveProp(writable: true)]
    public int $activeTab = 1; // Default active tab

    #[LiveProp]
    /** ['id' => 1, 'label' => 'Tab 1', 'i' => <index> ] */
    public array $tabs = [];

    #[LiveAction]
    public function changeTab(#[LiveArg] int $id): void
    {
        $this->activeTab = $id;
    }

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function mount(EventEntity $index): void
    {
        $this->index = $index;
        foreach ($index->getTags() as $tag) {
            if (array_key_first($tag) === 'a') {
                $ref = $tag[1];
                $relayHint = $tag[2] ?? null;
                list($kind,$npub,$slug) = explode(':',$ref);
                $cat = $this->entityManager->getRepository(EventEntity::class)->findOneBy(['slug' => $slug]);
                $next = count($this->tabs) + 1;
                $this->tabs[] = ['id' => $next, 'label' => $cat->getTitle(), 'i' => $cat];
            } elseif (array_key_first($tag) === 'e') {
                // ignore 'e's, we want 'a's
                //$cat = $this->entityManager->getRepository(EventEntity::class)->findOneBy(['id' => $tag[1]]);
                //$next = count($this->tabs) + 1;
                //$this->tabs[] = ['id' => $next, 'label' => $cat->getTitle()];
            }
        }
    }

    public function getTabContent(): string|array
    {
        // return current index
        return match ($this->activeTab) {
            1 => 'This will eventually be content.',
            2 => 'This is content for Tab 2. No AJAX needed!',
            default => 'No content available.',
        };
    }
}
