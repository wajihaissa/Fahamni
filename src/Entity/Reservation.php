<?php

namespace App\Entity;

use App\Repository\ReservationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReservationRepository::class)]
class Reservation
{
    public const STATUS_PENDING = 0;
    public const STATUS_ACCEPTED = 1;
    public const STATUS_PAID = 2;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $status = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $reservedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cancellAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $confirmationEmailSentAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptanceEmailSentAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reminderEmailSentAt = null;

    #[ORM\ManyToOne(inversedBy: 'reservations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Seance $seance = null;

    #[ORM\ManyToOne(inversedBy: 'reservations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $participant = null;

    /**
     * @var Collection<int, PaymentTransaction>
     */
    #[ORM\OneToMany(targetEntity: PaymentTransaction::class, mappedBy: 'reservation', orphanRemoval: true)]
    private Collection $paymentTransactions;

    public function __construct()
    {
        $this->paymentTransactions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function setStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getReservedAt(): ?\DateTimeImmutable
    {
        return $this->reservedAt;
    }

    public function setReservedAt(\DateTimeImmutable $reservedAt): static
    {
        $this->reservedAt = $reservedAt;

        return $this;
    }

    public function getCancellAt(): ?\DateTimeImmutable
    {
        return $this->cancellAt;
    }

    public function setCancellAt(?\DateTimeImmutable $cancellAt): static
    {
        $this->cancellAt = $cancellAt;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getConfirmationEmailSentAt(): ?\DateTimeImmutable
    {
        return $this->confirmationEmailSentAt;
    }

    public function setConfirmationEmailSentAt(?\DateTimeImmutable $confirmationEmailSentAt): static
    {
        $this->confirmationEmailSentAt = $confirmationEmailSentAt;

        return $this;
    }

    public function getAcceptanceEmailSentAt(): ?\DateTimeImmutable
    {
        return $this->acceptanceEmailSentAt;
    }

    public function setAcceptanceEmailSentAt(?\DateTimeImmutable $acceptanceEmailSentAt): static
    {
        $this->acceptanceEmailSentAt = $acceptanceEmailSentAt;

        return $this;
    }

    public function getReminderEmailSentAt(): ?\DateTimeImmutable
    {
        return $this->reminderEmailSentAt;
    }

    public function setReminderEmailSentAt(?\DateTimeImmutable $reminderEmailSentAt): static
    {
        $this->reminderEmailSentAt = $reminderEmailSentAt;

        return $this;
    }

    public function getSeance(): ?Seance
    {
        return $this->seance;
    }

    public function setSeance(?Seance $seance): static
    {
        $this->seance = $seance;

        return $this;
    }

    public function getParticipant(): ?User
    {
        return $this->participant;
    }

    public function setParticipant(?User $participant): static
    {
        $this->participant = $participant;

        return $this;
    }

    /**
     * @return Collection<int, PaymentTransaction>
     */
    public function getPaymentTransactions(): Collection
    {
        return $this->paymentTransactions;
    }

    public function addPaymentTransaction(PaymentTransaction $paymentTransaction): static
    {
        if (!$this->paymentTransactions->contains($paymentTransaction)) {
            $this->paymentTransactions->add($paymentTransaction);
            $paymentTransaction->setReservation($this);
        }

        return $this;
    }

    public function removePaymentTransaction(PaymentTransaction $paymentTransaction): static
    {
        if ($this->paymentTransactions->removeElement($paymentTransaction)) {
            if ($paymentTransaction->getReservation() === $this) {
                $paymentTransaction->setReservation(null);
            }
        }

        return $this;
    }
}
