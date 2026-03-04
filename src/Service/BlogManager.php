<?php

namespace App\Service;

use App\Entity\Blog;
use App\Entity\User;

class BlogManager
{
    public function validate(Blog $blog): bool
    {
        // Titre obligatoire
        if (empty($blog->getTitre())) {
            throw new \InvalidArgumentException('Le titre est obligatoire');
        }

        // Longueur max du titre
        if (strlen($blog->getTitre()) > 100) {
            throw new \InvalidArgumentException('Le titre ne doit pas dépasser 100 caractères');
        }

        // Contenu obligatoire
        if (empty($blog->getContent())) {
            throw new \InvalidArgumentException('Le contenu est obligatoire');
        }

        // Longueur minimale du contenu
        if (strlen($blog->getContent()) < 20) {
            throw new \InvalidArgumentException('Le contenu doit contenir au moins 20 caractères');
        }

        return true;
    }

    public function canEdit(Blog $blog, User $user): bool
    {
        if ($blog->getPublisher() === $user || in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        throw new \InvalidArgumentException('Vous n\'êtes pas autorisé à modifier cet article');
    }

    public function canDelete(Blog $blog, User $user): bool
    {
        if ($blog->getPublisher() === $user || in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        throw new \InvalidArgumentException('Vous n\'êtes pas autorisé à supprimer cet article');
    }
}