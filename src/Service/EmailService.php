<?php

namespace App\Service;

use App\Entity\Reservation;
use App\Entity\Seance;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

final class EmailService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $mailerDsn = '',
        private readonly string $fromAddress = 'no-reply@fahimni.local',
        private readonly string $fromName = 'Fahimni'
    ) {
    }

    public function sendReservationCreatedEmail(Reservation $reservation): void
    {
        $this->sendReservationEmail(
            $reservation,
            'Reservation en attente de validation | Fahimni',
            'emails/reservation_created.html.twig'
        );
    }

    public function sendReservationAcceptedEmail(Reservation $reservation): void
    {
        $this->sendReservationEmail(
            $reservation,
            'Reservation confirmee | Fahimni',
            'emails/reservation_accepted.html.twig'
        );
    }

    public function sendReservationReminderEmail(Reservation $reservation): void
    {
        $this->sendReservationEmail(
            $reservation,
            'Rappel de seance dans 24h | Fahimni',
            'emails/reservation_reminder.html.twig'
        );
    }

    private function sendReservationEmail(
        Reservation $reservation,
        string $subject,
        string $template
    ): void {
        $dsn = strtolower(trim($this->mailerDsn));
        if ($dsn === '' || $dsn === 'null://null') {
            throw new \LogicException('MAILER_DSN non configure: remplacez null://null par un vrai SMTP dans .env.local.');
        }

        $participant = $reservation->getParticipant();
        $seance = $reservation->getSeance();

        if (!$participant instanceof User || !$seance instanceof Seance) {
            throw new \LogicException('Reservation incomplete: participant ou seance manquant.');
        }

        $studentEmail = trim((string) $participant->getEmail());
        if ($studentEmail === '') {
            throw new \LogicException('Impossible d\'envoyer un email sans adresse etudiant.');
        }

        $startAt = $seance->getStartAt();
        if (!$startAt instanceof \DateTimeImmutable) {
            throw new \LogicException('Impossible d\'envoyer un email sans date de seance.');
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to(new Address($studentEmail, $participant->getFullName() ?? $studentEmail))
            ->subject($subject)
            ->htmlTemplate($template)
            ->context($this->buildReservationContext($reservation, $startAt));

        $this->mailer->send($email);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReservationContext(Reservation $reservation, \DateTimeImmutable $startAt): array
    {
        $participant = $reservation->getParticipant();
        $seance = $reservation->getSeance();
        $tuteur = $seance?->getTuteur();

        return [
            'reservation' => $reservation,
            'etudiantName' => $participant?->getFullName() ?? 'Etudiant',
            'tuteurName' => $tuteur?->getFullName() ?? 'Tuteur',
            'matiere' => $seance?->getMatiere() ?? 'Seance',
            'seanceDate' => $startAt->format('d/m/Y'),
            'seanceTime' => $startAt->format('H:i'),
            'seanceDateTime' => $startAt,
            'supportEmail' => $this->fromAddress,
        ];
    }
}
