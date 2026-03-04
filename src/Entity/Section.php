<?php

namespace App\Entity;

use App\Repository\SectionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SectionRepository::class)]
class Section
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    #[ORM\ManyToOne(inversedBy: 'sections')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Chapter $chapter = null;

    /**
     * @var Collection<int, Resource>
     */
    #[ORM\OneToMany(targetEntity: Resource::class, mappedBy: 'section', orphanRemoval: true)]
    private Collection $resources;

    /**
     * @var Collection<int, FlashcardAttempt>
     */
    #[ORM\OneToMany(targetEntity: FlashcardAttempt::class, mappedBy: 'section')]
    private Collection $flashcardAttempts;

    public function __construct()
    {
        $this->resources = new ArrayCollection();
        $this->flashcardAttempts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getChapter(): ?Chapter
    {
        return $this->chapter;
    }

    public function setChapter(?Chapter $chapter): static
    {
        $this->chapter = $chapter;

        return $this;
    }

    /**
     * @return Collection<int, Resource>
     */
    public function getResources(): Collection
    {
        return $this->resources;
    }

    public function addResource(Resource $resource): static
    {
        if (!$this->resources->contains($resource)) {
            $this->resources->add($resource);
            $resource->setSection($this);
        }

        return $this;
    }

    public function removeResource(Resource $resource): static
    {
        if ($this->resources->removeElement($resource)) {
            // set the owning side to null (unless already changed)
            if ($resource->getSection() === $this) {
                $resource->setSection(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FlashcardAttempt>
     */
    public function getFlashcardAttempts(): Collection
    {
        return $this->flashcardAttempts;
    }

    public function addFlashcardAttempt(FlashcardAttempt $flashcardAttempt): static
    {
        if (!$this->flashcardAttempts->contains($flashcardAttempt)) {
            $this->flashcardAttempts->add($flashcardAttempt);
            $flashcardAttempt->setSection($this);
        }

        return $this;
    }

    public function removeFlashcardAttempt(FlashcardAttempt $flashcardAttempt): static
    {
        if ($this->flashcardAttempts->removeElement($flashcardAttempt)) {
            // set the owning side to null (unless already changed)
            if ($flashcardAttempt->getSection() === $this) {
                $flashcardAttempt->setSection(null);
            }
        }

        return $this;
    }
}
