<?php

namespace App\Entity;

use App\Repository\FlashcardAttemptRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FlashcardAttemptRepository::class)]
class FlashcardAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $question = null;

    #[ORM\Column(length: 255)]
    private ?string $userAnswer = null;

    #[ORM\Column(length: 255)]
    private ?string $aiFeedback = null;

    #[ORM\Column]
    private ?bool $isCorrect = null;

    #[ORM\ManyToOne(inversedBy: 'flashcardAttempts')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Matiere $subject = null;

    #[ORM\ManyToOne(inversedBy: 'flashcardAttempts')]
    private ?Section $section = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuestion(): ?string
    {
        return $this->question;
    }

    public function setQuestion(string $question): static
    {
        $this->question = $question;

        return $this;
    }

    public function getUserAnswer(): ?string
    {
        return $this->userAnswer;
    }

    public function setUserAnswer(string $userAnswer): static
    {
        $this->userAnswer = $userAnswer;

        return $this;
    }

    public function getAiFeedback(): ?string
    {
        return $this->aiFeedback;
    }

    public function setAiFeedback(string $aiFeedback): static
    {
        $this->aiFeedback = $aiFeedback;

        return $this;
    }

    public function isCorrect(): ?bool
    {
        return $this->isCorrect;
    }

    public function setIsCorrect(bool $isCorrect): static
    {
        $this->isCorrect = $isCorrect;

        return $this;
    }

    public function getSubject(): ?Matiere
    {
        return $this->subject;
    }

    public function setSubject(?Matiere $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getSection(): ?Section
    {
        return $this->section;
    }

    public function setSection(?Section $section): static
    {
        $this->section = $section;

        return $this;
    }
}
