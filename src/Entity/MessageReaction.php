<?php

namespace App\Entity;

use App\Repository\MessageReactionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MessageReactionRepository::class)]
class MessageReaction
{
    //Constantes pour les types d'emojis
    public const EMOJI_LIKE = 0;      // 👍
    public const EMOJI_LOVE = 1;      // ❤️
    public const EMOJI_LAUGH = 2;     // 😂
    public const EMOJI_WOW = 3;       // 😮
    public const EMOJI_SAD = 4;       // 😢
    public const EMOJI_ANGRY = 5;     // 😡
    
    // Mapping emoji type vers emoji unicode
    public const EMOJI_MAP = [
        self::EMOJI_LIKE => '👍',
        self::EMOJI_LOVE => '❤️',
        self::EMOJI_LAUGH => '😂',
        self::EMOJI_WOW => '😮',
        self::EMOJI_SAD => '😢',
        self::EMOJI_ANGRY => '😡',
    ];


    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $emoji = null;

    

    #[ORM\ManyToOne(inversedBy: 'messageReactions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $reactor = null;

    #[ORM\ManyToOne(inversedBy: 'reactions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Message $message = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmoji(): ?int
    {
        return $this->emoji;
    }

    public function setEmoji(int $emoji): static
    {
        $this->emoji = $emoji;

        return $this;   
    }

    public function getMessage(): ?\App\Entity\Message
    {
        return $this->message;
    }

    public function setMessage(?\App\Entity\Message $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getReactor(): ?User
    {
        return $this->reactor;
    }

    public function setReactor(?User $reactor): static
    {
        $this->reactor = $reactor;

        return $this;
    }
}
