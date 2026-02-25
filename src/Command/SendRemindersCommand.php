<?php

namespace App\Command;

use App\Entity\Reservation;
use App\Repository\ReservationRepository;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:send-reminders',
    description: 'Envoie les rappels email 24h avant la seance pour les reservations acceptees/payees.'
)]
final class SendRemindersCommand extends Command
{
    private const STATUS_ACCEPTED = Reservation::STATUS_ACCEPTED;
    private const STATUS_PAID = Reservation::STATUS_PAID;

    public function __construct(
        private readonly ReservationRepository $reservationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailService $emailService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();
        $windowStart = $now->modify('+23 hours');
        $windowEnd = $now->modify('+24 hours');

        $reservations = $this->reservationRepository->createQueryBuilder('r')
            ->innerJoin('r.seance', 's')->addSelect('s')
            ->leftJoin('s.tuteur', 't')->addSelect('t')
            ->leftJoin('r.participant', 'p')->addSelect('p')
            ->andWhere('r.status IN (:statuses)')
            ->andWhere('r.reminderEmailSentAt IS NULL')
            ->andWhere('s.startAt > :windowStart')
            ->andWhere('s.startAt <= :windowEnd')
            ->setParameter('statuses', [self::STATUS_ACCEPTED, self::STATUS_PAID])
            ->setParameter('windowStart', $windowStart)
            ->setParameter('windowEnd', $windowEnd)
            ->orderBy('s.startAt', 'ASC')
            ->getQuery()
            ->getResult();

        if ($reservations === []) {
            $io->success('Aucun rappel a envoyer.');

            return Command::SUCCESS;
        }

        $sentCount = 0;
        $failedCount = 0;

        foreach ($reservations as $reservation) {
            if (!$reservation instanceof Reservation) {
                continue;
            }

            try {
                $this->emailService->sendReservationReminderEmail($reservation);
                $reservation->setReminderEmailSentAt(new \DateTimeImmutable());
                ++$sentCount;
            } catch (\Throwable $exception) {
                ++$failedCount;
                $io->error(sprintf(
                    'Echec rappel reservation #%d: %s',
                    (int) ($reservation->getId() ?? 0),
                    $exception->getMessage()
                ));
            }
        }

        if ($sentCount > 0) {
            $this->entityManager->flush();
        }

        if ($failedCount > 0) {
            $io->warning(sprintf(
                'Rappels envoyes: %d. Echecs: %d.',
                $sentCount,
                $failedCount
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf('Rappels envoyes avec succes: %d.', $sentCount));

        return Command::SUCCESS;
    }
}
