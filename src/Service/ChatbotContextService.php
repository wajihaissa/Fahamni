<?php

namespace App\Service;

use App\Entity\User;

/**
 * Construit un résumé des données de l'utilisateur pour le contexte du chatbot (Mistral).
 */
final class ChatbotContextService
{
    private const MAX_ITEMS = 15;
    private const DATE_FORMAT = 'd/m/Y H:i';

    public function buildUserContext(User $user): string
    {
        $now = new \DateTimeImmutable();
        $in30Days = $now->modify('+30 days');
        $sections = [];

        // Profil
        $profile = $user->getProfile();
        $roleLabel = $profile?->getRoles() ? (stripos((string) $profile->getRoles(), 'tuteur') !== false ? 'tuteur' : 'apprenant') : 'utilisateur';
        $sections[] = sprintf(
            "Utilisateur : %s (email: %s). Rôle sur la plateforme : %s.",
            $user->getFullName() ?? 'Inconnu',
            $user->getEmail() ?? '',
            $roleLabel
        );

        // Réservations (séances où il est participant)
        $reservations = $user->getReservations();
        $upcomingReservations = [];
        foreach ($reservations as $r) {
            $seance = $r->getSeance();
            if (!$seance || $r->getCancellAt() !== null) {
                continue;
            }
            $start = $seance->getStartAt();
            if ($start && $start >= $now && $start <= $in30Days) {
                $upcomingReservations[] = sprintf(
                    '- %s le %s (%d min) avec tuteur',
                    $seance->getMatiere(),
                    $start->format(self::DATE_FORMAT),
                    $seance->getDurationMin()
                );
            }
        }
        $upcomingReservations = array_slice($upcomingReservations, 0, self::MAX_ITEMS);
        if ($upcomingReservations !== []) {
            $sections[] = "Prochaines réservations de séances :\n" . implode("\n", $upcomingReservations);
        } else {
            $total = $reservations->count();
            $sections[] = $total > 0
                ? "L'utilisateur a déjà fait des réservations de séances (total : {$total}). Aucune prochaine dans les 30 jours."
                : "L'utilisateur n'a pas encore de réservation de séance.";
        }

        // Séances créées (en tant que tuteur)
        $seances = $user->getSeances();
        $upcomingSeances = [];
        foreach ($seances as $s) {
            $start = $s->getStartAt();
            if ($start && $start >= $now && $start <= $in30Days) {
                $upcomingSeances[] = sprintf(
                    '- %s le %s (%d min, max %d participants)',
                    $s->getMatiere(),
                    $start->format(self::DATE_FORMAT),
                    $s->getDurationMin(),
                    $s->getMaxParticipants()
                );
            }
        }
        $upcomingSeances = array_slice($upcomingSeances, 0, self::MAX_ITEMS);
        if ($upcomingSeances !== []) {
            $sections[] = "Prochaines séances (en tant que tuteur) :\n" . implode("\n", $upcomingSeances);
        } elseif ($seances->count() > 0) {
            $sections[] = "L'utilisateur est tuteur et a créé des séances (total : " . $seances->count() . "). Aucune dans les 30 prochains jours.";
        }

        // Articles / blogs publiés
        $blogs = $user->getBlogs();
        $blogCount = $blogs->count();
        if ($blogCount > 0) {
            $titles = [];
            foreach ($blogs as $b) {
                $titles[] = $b->getTitre();
                if (count($titles) >= 5) {
                    break;
                }
            }
            $sections[] = "Articles publiés : {$blogCount} au total. Exemples : " . implode(', ', $titles);
        } else {
            $sections[] = "Aucun article publié.";
        }

        // Conversations (messagerie)
        $conversations = $user->getConversations();
        $convCount = $conversations->count();
        $sections[] = $convCount > 0
            ? "Nombre de conversations dans la messagerie : {$convCount}."
            : "L'utilisateur n'a pas encore de conversation dans la messagerie.";

        // Rappel fonctionnalités de l'app
        $sections[] = "Fonctionnalités de la plateforme Fahamni : matching tuteur/apprenant, réservation de séances, quiz, recherche de tuteurs, articles de blog (tuteurs), messagerie entre utilisateurs, signalement de messages/conversations (avec envoi d'email d'avertissement par l'admin).";

        return implode("\n\n", $sections);
    }
}
