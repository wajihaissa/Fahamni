<?php

namespace        App\Service;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Détermine l'utilisateur connecté pour la messagerie (authentification requise).
 */
final class MessengerActorResolver
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function getActor(): ?User
    {
        $user = $this->security->getUser();
        return $user instanceof User ? $user : null;
    }
}
