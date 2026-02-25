<?php

namespace App\Entity;

use App\Repository\MessageAttachmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * Pièce jointe d'un message (image ou fichier).
 * Le fichier est géré par VichUploader : après persist(), le bundle déplace
 * le fichier dans public/uploads/messenger/ et remplit automatiquement fileName.
 */
#[ORM\Entity(repositoryClass: MessageAttachmentRepository::class)]
#[Vich\Uploadable]
class MessageAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Message $message = null;

    /** Nom du fichier tel qu’envoyé par l’utilisateur (affichage / téléchargement). */
    #[ORM\Column(length: 255)]
    private ?string $originalName = null;

    /** Nom du fichier sur le disque (généré par VichUploader, ex. uniqid). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fileName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    private ?int $size = null;

    /**
     * Fichier uploadé (non persisté en BDD). Vich déplace ce fichier à l’upload
     * et remplit fileName.
     */
    #[Vich\UploadableField(mapping: 'messenger_attachment', fileNameProperty: 'fileName')]
    private ?File $attachmentFile = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function setMessage(?Message $message): static
    {
        $this->message = $message;
        return $this;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function setOriginalName(string $originalName): static
    {
        $this->originalName = $originalName;
        return $this;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileName(?string $fileName): static
    {
        $this->fileName = $fileName;
        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(?int $size): static
    {
        $this->size = $size;
        return $this;
    }

    public function getAttachmentFile(): ?File
    {
        return $this->attachmentFile;
    }

    public function setAttachmentFile(?File $attachmentFile): static
    {
        $this->attachmentFile = $attachmentFile;
        return $this;
    }

    /** Indique si la pièce jointe est une image (pour l’affichage inline). */
    public function isImage(): bool
    {
        return $this->mimeType !== null && str_starts_with($this->mimeType, 'image/');
    }

    /** Indique si la pièce jointe est un audio (message vocal). */
    public function isAudio(): bool
    {
        return $this->mimeType !== null && str_starts_with($this->mimeType, 'audio/');
    }
}
