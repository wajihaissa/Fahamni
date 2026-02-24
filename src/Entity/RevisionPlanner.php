<?php

namespace App\Entity;

use App\Repository\RevisionPlannerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RevisionPlannerRepository::class)]
class RevisionPlanner
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $student = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $examDate = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $focusSubject = null;

    #[ORM\Column(length: 20)]
    private ?string $difficultyLevel = null;

    #[ORM\Column]
    private ?int $dailySessions = null;

    #[ORM\Column]
    private ?bool $includeWeekend = null;

    #[ORM\Column(length: 5)]
    private ?string $reminderTime = null;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $planData = [];

    /**
     * @var array<string, bool>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $progressData = null;

    #[ORM\Column]
    private ?int $totalEntries = 0;

    #[ORM\Column]
    private ?int $completedEntries = 0;

    #[ORM\Column]
    private ?float $completionRate = 0.0;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function setStudent(?User $student): static
    {
        $this->student = $student;

        return $this;
    }

    public function getExamDate(): ?\DateTimeImmutable
    {
        return $this->examDate;
    }

    public function setExamDate(\DateTimeImmutable $examDate): static
    {
        $this->examDate = $examDate;

        return $this;
    }

    public function getFocusSubject(): ?string
    {
        return $this->focusSubject;
    }

    public function setFocusSubject(?string $focusSubject): static
    {
        $this->focusSubject = $focusSubject;

        return $this;
    }

    public function getDifficultyLevel(): ?string
    {
        return $this->difficultyLevel;
    }

    public function setDifficultyLevel(string $difficultyLevel): static
    {
        $this->difficultyLevel = $difficultyLevel;

        return $this;
    }

    public function getDailySessions(): ?int
    {
        return $this->dailySessions;
    }

    public function setDailySessions(int $dailySessions): static
    {
        $this->dailySessions = $dailySessions;

        return $this;
    }

    public function isIncludeWeekend(): ?bool
    {
        return $this->includeWeekend;
    }

    public function setIncludeWeekend(bool $includeWeekend): static
    {
        $this->includeWeekend = $includeWeekend;

        return $this;
    }

    public function getReminderTime(): ?string
    {
        return $this->reminderTime;
    }

    public function setReminderTime(string $reminderTime): static
    {
        $this->reminderTime = $reminderTime;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPlanData(): array
    {
        return $this->planData;
    }

    /**
     * @param array<string, mixed> $planData
     */
    public function setPlanData(array $planData): static
    {
        $this->planData = $planData;

        return $this;
    }

    /**
     * @return array<string, bool>|null
     */
    public function getProgressData(): ?array
    {
        return $this->progressData;
    }

    /**
     * @param array<string, bool>|null $progressData
     */
    public function setProgressData(?array $progressData): static
    {
        $this->progressData = $progressData;

        return $this;
    }

    public function getTotalEntries(): ?int
    {
        return $this->totalEntries;
    }

    public function setTotalEntries(int $totalEntries): static
    {
        $this->totalEntries = $totalEntries;

        return $this;
    }

    public function getCompletedEntries(): ?int
    {
        return $this->completedEntries;
    }

    public function setCompletedEntries(int $completedEntries): static
    {
        $this->completedEntries = $completedEntries;

        return $this;
    }

    public function getCompletionRate(): ?float
    {
        return $this->completionRate;
    }

    public function setCompletionRate(float $completionRate): static
    {
        $this->completionRate = $completionRate;

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
}

