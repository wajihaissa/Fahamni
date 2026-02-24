<?php

namespace App\Twig;

use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class SessionUserExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function getGlobals(): array
    {
        return ['user' => $this->security->getUser()];
    }
}
