<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(nullable: true)]
    private ?bool $status = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(length: 255)]
    private ?string $FullName = null;

    /**
     * Surnoms par conversation : ["convId_targetUserId" => "nickname"]
     * @var array<string, string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $conversationNicknames = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $passkeyCredentialId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $passkeyPublicKeyPem = null;

    #[ORM\Column(nullable: true)]
    private ?int $passkeySignCount = null;

    #[ORM\OneToOne(mappedBy: 'userId', cascade: ['persist', 'remove'])]
    private ?Student $profile = null;

    /**
     * @var Collection<int, Blog>
     */
    #[ORM\OneToMany(targetEntity: Blog::class, mappedBy: 'publisher', orphanRemoval: true)]
    private Collection $blogs;

    /**
     * @var Collection<int, Interaction>
     */
    #[ORM\OneToMany(targetEntity: Interaction::class, mappedBy: 'innteractor', orphanRemoval: true)]
    private Collection $interactions;

    /**
     * @var Collection<int, Conversation>
     */
    #[ORM\ManyToMany(targetEntity: Conversation::class, mappedBy: 'participants')]
    private Collection $conversations;

    /**
     * @var Collection<int, Conversation>
     */
    #[ORM\OneToMany(targetEntity: Conversation::class, mappedBy: 'createdBy')]
    private Collection $createdConversations;

    /**
     * @var Collection<int, Message>
     */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'sender')]
    private Collection $sentMessages;

    /**
     * @var Collection<int, Message>
     */
    #[ORM\ManyToMany(targetEntity: Message::class, mappedBy: 'readBy')]
    private Collection $readMessages;

    /**
     * @var Collection<int, MessageReaction>
     */
    #[ORM\OneToMany(targetEntity: MessageReaction::class, mappedBy: 'reactor')]
    private Collection $messageReactions;

    /**
     * @var Collection<int, Seance>
     */
    #[ORM\OneToMany(targetEntity: Seance::class, mappedBy: 'tuteur')]
    private Collection $seances;

    /**
     * @var Collection<int, Reservation>
     */
    #[ORM\OneToMany(targetEntity: Reservation::class, mappedBy: 'participant')]
    private Collection $reservations;

    /**
     * @var Collection<int, QuizResult>
     */
    #[ORM\OneToMany(targetEntity: QuizResult::class, mappedBy: 'user')]
    private Collection $quizResults;

    public function __construct()
    {
        $this->blogs = new ArrayCollection();
        $this->interactions = new ArrayCollection();
        $this->conversations = new ArrayCollection();
        $this->createdConversations = new ArrayCollection();
        $this->sentMessages = new ArrayCollection();
        $this->readMessages = new ArrayCollection();
        $this->messageReactions = new ArrayCollection();
        $this->seances = new ArrayCollection();
        $this->reservations = new ArrayCollection();
        $this->quizResults = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // @deprecated, to be removed when upgrading to Symfony 8
    }

    public function isStatus(): ?bool
    {
        return $this->status;
    }

    public function setStatus(?bool $status): static
    {
        $this->status = $status;

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

    public function getFullName(): ?string
    {
        return $this->FullName;
    }

    public function setFullName(string $FullName): static
    {
        $this->FullName = $FullName;

        return $this;
    }

    /** Surnom affiché pour un contact dans une conversation (uniquement pour cet utilisateur). */
    public function getConversationNickname(int $conversationId, int $targetUserId): ?string
    {
        $key = $conversationId . '_' . $targetUserId;
        return $this->conversationNicknames[$key] ?? null;
    }

    public function setConversationNickname(int $conversationId, int $targetUserId, ?string $nickname): static
    {
        $this->conversationNicknames ??= [];
        $key = $conversationId . '_' . $targetUserId;
        if ($nickname === null || $nickname === '') {
            unset($this->conversationNicknames[$key]);
        } else {
            $this->conversationNicknames[$key] = mb_substr($nickname, 0, 120);
        }
        return $this;
    }

    public function getProfile(): ?Student
    {
        return $this->profile;
    }

    public function setProfile(Student $profile): static
    {
        // set the owning side of the relation if necessary
        if ($profile->getUserId() !== $this) {
            $profile->setUserId($this);
        }

        $this->profile = $profile;

        return $this;
    }

    public function getPasskeyCredentialId(): ?string
    {
        return $this->passkeyCredentialId;
    }

    public function setPasskeyCredentialId(?string $passkeyCredentialId): static
    {
        $this->passkeyCredentialId = $passkeyCredentialId;

        return $this;
    }

    public function getPasskeyPublicKeyPem(): ?string
    {
        return $this->passkeyPublicKeyPem;
    }

    public function setPasskeyPublicKeyPem(?string $passkeyPublicKeyPem): static
    {
        $this->passkeyPublicKeyPem = $passkeyPublicKeyPem;

        return $this;
    }

    public function getPasskeySignCount(): ?int
    {
        return $this->passkeySignCount;
    }

    public function setPasskeySignCount(?int $passkeySignCount): static
    {
        $this->passkeySignCount = $passkeySignCount;

        return $this;
    }

    /**
     * @return Collection<int, Blog>
     */
    public function getBlogs(): Collection
    {
        return $this->blogs;
    }

    public function addBlog(Blog $blog): static
    {
        if (!$this->blogs->contains($blog)) {
            $this->blogs->add($blog);
            $blog->setPublisher($this);
        }

        return $this;
    }

    public function removeBlog(Blog $blog): static
    {
        if ($this->blogs->removeElement($blog)) {
            // set the owning side to null (unless already changed)
            if ($blog->getPublisher() === $this) {
                $blog->setPublisher(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Interaction>
     */
    public function getInteractions(): Collection
    {
        return $this->interactions;
    }

    public function addInteraction(Interaction $interaction): static
    {
        if (!$this->interactions->contains($interaction)) {
            $this->interactions->add($interaction);
            $interaction->setInnteractor($this);
        }

        return $this;
    }

    public function removeInteraction(Interaction $interaction): static
    {
        if ($this->interactions->removeElement($interaction)) {
            // set the owning side to null (unless already changed)
            if ($interaction->getInnteractor() === $this) {
                $interaction->setInnteractor(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Conversation>
     */
    public function getConversations(): Collection
    {
        return $this->conversations;
    }

    public function addConversation(Conversation $conversation): static
    {
        if (!$this->conversations->contains($conversation)) {
            $this->conversations->add($conversation);
            $conversation->addParticipant($this);
        }

        return $this;
    }

    public function removeConversation(Conversation $conversation): static
    {
        if ($this->conversations->removeElement($conversation)) {
            $conversation->removeParticipant($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, Conversation>
     */
    public function getCreatedConversations(): Collection
    {
        return $this->createdConversations;
    }

    public function addCreatedConversation(Conversation $createdConversation): static
    {
        if (!$this->createdConversations->contains($createdConversation)) {
            $this->createdConversations->add($createdConversation);
            $createdConversation->setCreatedBy($this);
        }

        return $this;
    }

    public function removeCreatedConversation(Conversation $createdConversation): static
    {
        if ($this->createdConversations->removeElement($createdConversation)) {
            // set the owning side to null (unless already changed)
            if ($createdConversation->getCreatedBy() === $this) {
                $createdConversation->setCreatedBy(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getSentMessages(): Collection
    {
        return $this->sentMessages;
    }

    public function addSentMessage(Message $sentMessage): static
    {
        if (!$this->sentMessages->contains($sentMessage)) {
            $this->sentMessages->add($sentMessage);
            $sentMessage->setSender($this);
        }

        return $this;
    }

    public function removeSentMessage(Message $sentMessage): static
    {
        if ($this->sentMessages->removeElement($sentMessage)) {
            // set the owning side to null (unless already changed)
            if ($sentMessage->getSender() === $this) {
                $sentMessage->setSender(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getReadMessages(): Collection
    {
        return $this->readMessages;
    }

    public function addReadMessage(Message $readMessage): static
    {
        if (!$this->readMessages->contains($readMessage)) {
            $this->readMessages->add($readMessage);
            $readMessage->addReadBy($this);
        }

        return $this;
    }

    public function removeReadMessage(Message $readMessage): static
    {
        if ($this->readMessages->removeElement($readMessage)) {
            $readMessage->removeReadBy($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, MessageReaction>
     */
    public function getMessageReactions(): Collection
    {
        return $this->messageReactions;
    }

    public function addMessageReaction(MessageReaction $messageReaction): static
    {
        if (!$this->messageReactions->contains($messageReaction)) {
            $this->messageReactions->add($messageReaction);
            $messageReaction->setReactor($this);
        }

        return $this;
    }

    public function removeMessageReaction(MessageReaction $messageReaction): static
    {
        if ($this->messageReactions->removeElement($messageReaction)) {
            // set the owning side to null (unless already changed)
            if ($messageReaction->getReactor() === $this) {
                $messageReaction->setReactor(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Seance>
     */
    public function getSeances(): Collection
    {
        return $this->seances;
    }

    public function addSeance(Seance $seance): static
    {
        if (!$this->seances->contains($seance)) {
            $this->seances->add($seance);
            $seance->setTuteur($this);
        }

        return $this;
    }

    public function removeSeance(Seance $seance): static
    {
        if ($this->seances->removeElement($seance)) {
            // set the owning side to null (unless already changed)
            if ($seance->getTuteur() === $this) {
                $seance->setTuteur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Reservation>
     */
    public function getReservations(): Collection
    {
        return $this->reservations;
    }

    public function addReservation(Reservation $reservation): static
    {
        if (!$this->reservations->contains($reservation)) {
            $this->reservations->add($reservation);
            $reservation->setParticipant($this);
        }

        return $this;
    }

    public function removeReservation(Reservation $reservation): static
    {
        if ($this->reservations->removeElement($reservation)) {
            // set the owning side to null (unless already changed)
            if ($reservation->getParticipant() === $this) {
                $reservation->setParticipant(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QuizResult>
     */
    public function getQuizResults(): Collection
    {
        return $this->quizResults;
    }

    public function addQuizResult(QuizResult $quizResult): static
    {
        if (!$this->quizResults->contains($quizResult)) {
            $this->quizResults->add($quizResult);
            $quizResult->setUser($this);
        }

        return $this;
    }

    public function removeQuizResult(QuizResult $quizResult): static
    {
        if ($this->quizResults->removeElement($quizResult)) {
            // set the owning side to null (unless already changed)
            if ($quizResult->getUser() === $this) {
                $quizResult->setUser(null);
            }
        }

        return $this;
    }
}
