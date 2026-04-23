<?php

namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class UserMenu
{
    use DefaultActionTrait;

    /** When true, render for the mobile header menu (not fixed in the left column). */
    #[LiveProp]
    public bool $inline = false;
}
