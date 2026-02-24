<?php

namespace App\Entity;

use App\Repository\InteractionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InteractionRepository::class)]
class Interaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type:'integer', nullable: true)]
    private ?int $reaction = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne(inversedBy: 'interactions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $innteractor = null;

    #[ORM\ManyToOne(inversedBy: 'interactions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Blog $blog = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isNotifRead = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isFlagged = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isDeletedByAdmin = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReaction(): ?int
    {
        return $this->reaction;
    }

    public function setReaction(?int $reaction): static
    {
        $this->reaction = $reaction;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

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

    public function getInnteractor(): ?User
    {
        return $this->innteractor;
    }

    public function setInnteractor(?User $innteractor): static
    {
        $this->innteractor = $innteractor;

        return $this;
    }

    public function getBlog(): ?Blog
    {
        return $this->blog;
    }

    public function setBlog(?Blog $blog): static
    {
        $this->blog = $blog;

        return $this;
    }

    public function isNotifRead(): bool
    {
        return $this->isNotifRead;
    }

    public function setIsNotifRead(bool $isNotifRead): static
    {
        $this->isNotifRead = $isNotifRead;

        return $this;
    }

    public function isFlagged(): bool
    {
        return $this->isFlagged;
    }

    public function setIsFlagged(bool $isFlagged): static
    {
        $this->isFlagged = $isFlagged;

        return $this;
    }

    public function isDeletedByAdmin(): bool
    {
        return $this->isDeletedByAdmin;
    }

    public function setIsDeletedByAdmin(bool $isDeletedByAdmin): static
    {
        $this->isDeletedByAdmin = $isDeletedByAdmin;

        return $this;
    }
}
