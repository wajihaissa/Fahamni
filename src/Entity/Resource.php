<?php

namespace App\Entity;

use App\Repository\ResourceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ResourceRepository::class)]
class Resource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    #[ORM\Column(length: 20)]
    private ?string $type = null;

    #[ORM\ManyToOne(inversedBy: 'resources')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Section $section = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filepath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $link = null;

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

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

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

    public function getFilepath(): ?string
    {
        return $this->filepath;
    }

    public function setFilepath(?string $filepath): static
    {
        $this->filepath = $filepath;

        return $this;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function setLink(?string $link): static
    {
        $this->link = $link;

        return $this;
    }
}
