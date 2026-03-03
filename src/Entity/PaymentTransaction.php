<?php

namespace App\Entity;

use App\Repository\PaymentTransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentTransactionRepository::class)]
#[ORM\Table(name: 'payment_transaction')]
#[ORM\UniqueConstraint(name: 'uniq_payment_checkout_session', columns: ['stripe_checkout_session_id'])]
class PaymentTransaction
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'paymentTransactions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Reservation $reservation = null;

    #[ORM\Column(length: 191)]
    private ?string $stripeCheckoutSessionId = null;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    #[ORM\Column]
    private ?int $amountCents = null;

    #[ORM\Column(length: 3)]
    private ?string $currency = null;

    #[ORM\Column(length: 30)]
    private ?string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $paymentMethodType = null;

    #[ORM\Column(length: 180)]
    private ?string $studentEmail = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReservation(): ?Reservation
    {
        return $this->reservation;
    }

    public function setReservation(?Reservation $reservation): static
    {
        $this->reservation = $reservation;

        return $this;
    }

    public function getStripeCheckoutSessionId(): ?string
    {
        return $this->stripeCheckoutSessionId;
    }

    public function setStripeCheckoutSessionId(string $stripeCheckoutSessionId): static
    {
        $this->stripeCheckoutSessionId = $stripeCheckoutSessionId;

        return $this;
    }

    public function getStripePaymentIntentId(): ?string
    {
        return $this->stripePaymentIntentId;
    }

    public function setStripePaymentIntentId(?string $stripePaymentIntentId): static
    {
        $this->stripePaymentIntentId = $stripePaymentIntentId;

        return $this;
    }

    public function getAmountCents(): ?int
    {
        return $this->amountCents;
    }

    public function setAmountCents(int $amountCents): static
    {
        $this->amountCents = $amountCents;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = strtolower($currency);

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getPaymentMethodType(): ?string
    {
        return $this->paymentMethodType;
    }

    public function setPaymentMethodType(?string $paymentMethodType): static
    {
        $this->paymentMethodType = $paymentMethodType;

        return $this;
    }

    public function getStudentEmail(): ?string
    {
        return $this->studentEmail;
    }

    public function setStudentEmail(string $studentEmail): static
    {
        $this->studentEmail = $studentEmail;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): static
    {
        $this->paidAt = $paidAt;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }
}
