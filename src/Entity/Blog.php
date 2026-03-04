<?php

namespace App\Entity;

use App\Repository\BlogRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BlogRepository::class)]
class Blog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le titre est obligatoire.')]
    #[Assert\Length(
        min: 3,
        max: 255,
        minMessage: 'Le titre doit contenir au moins {{ limit }} caractères.',
        maxMessage: 'Le titre ne peut pas dépasser {{ limit }} caractères.'
    )]
    private ?string $titre = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Le contenu est obligatoire.')]
    #[Assert\Length(
        min: 10,
        minMessage: 'Le contenu doit contenir au moins {{ limit }} caractères.'
    )]
    private ?string $content = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $images = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le statut est obligatoire.')]
    #[Assert\Choice(
        choices: ['published', 'draft', 'pending', 'rejected'],
        message: 'Le statut doit être "published", "draft", "pending" ou "rejected".'
    )]
    private ?string $status = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    // ✅ COLONNE CATEGORY
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Choice(
        choices: ['study-tips', 'mathematics', 'science', 'computer-science'],
        message: 'Veuillez choisir une catégorie valide.'
    )]
    private ?string $category = null;

    // 💙 COMPTEURS D'INTERACTIONS
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $likesCount = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $commentsCount = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isStatusNotifRead = false;

    #[ORM\ManyToOne(inversedBy: 'blogs')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $publisher = null;

    /**
     * @var Collection<int, Interaction>
     */
    #[ORM\OneToMany(
        targetEntity: Interaction::class,
        mappedBy: 'blog',
        cascade: ['remove'],
        orphanRemoval: true
    )]
    private Collection $interactions;

    public function __construct()
    {
        $this->interactions = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): self
    {
        $this->titre = $titre;
        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getImages(): ?array
    {
        return $this->images;
    }

    public function setImages(?array $images): self
    {
        $this->images = $images;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): self
    {
        $this->publishedAt = $publishedAt;
        return $this;
    }

    // ✅ GETTER / SETTER CATEGORY
    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): self
    {
        $this->category = $category;
        return $this;
    }

    // 💙 GETTERS / SETTERS POUR LES COMPTEURS D'INTERACTIONS
    public function getLikesCount(): int
    {
        return $this->likesCount;
    }

    public function setLikesCount(int $likesCount): self
    {
        $this->likesCount = $likesCount;
        return $this;
    }

    public function incrementLikesCount(): self
    {
        $this->likesCount++;
        return $this;
    }

    public function decrementLikesCount(): self
    {
        $this->likesCount = max(0, $this->likesCount - 1);
        return $this;
    }

    public function getCommentsCount(): int
    {
        return $this->commentsCount;
    }

    public function setCommentsCount(int $commentsCount): self
    {
        $this->commentsCount = $commentsCount;
        return $this;
    }

    public function incrementCommentsCount(): self
    {
        $this->commentsCount++;
        return $this;
    }

    public function decrementCommentsCount(): self
    {
        $this->commentsCount = max(0, $this->commentsCount - 1);
        return $this;
    }

    public function getPublisher(): ?User
    {
        return $this->publisher;
    }

    public function setPublisher(User $publisher): self
    {
        $this->publisher = $publisher;
        return $this;
    }

    /**
     * @return Collection<int, Interaction>
     */
    public function getInteractions(): Collection
    {
        return $this->interactions;
    }

    public function addInteraction(Interaction $interaction): self
    {
        if (!$this->interactions->contains($interaction)) {
            $this->interactions->add($interaction);
            $interaction->setBlog($this);
        }
        return $this;
    }

    public function removeInteraction(Interaction $interaction): self
    {
        if ($this->interactions->removeElement($interaction)) {
            if ($interaction->getBlog() === $this) {
                $interaction->setBlog(null);
            }
        }
        return $this;
    }

    public function isStatusNotifRead(): bool
    {
        return $this->isStatusNotifRead;
    }

    public function setIsStatusNotifRead(bool $isStatusNotifRead): self
    {
        $this->isStatusNotifRead = $isStatusNotifRead;
        return $this;
    }
}
