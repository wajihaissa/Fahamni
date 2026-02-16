<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260209234538 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE blog CHANGE images images JSON DEFAULT NULL, CHANGE published_at published_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE conversation ADD is_archived TINYINT(1) DEFAULT 0 NOT NULL, CHANGE title title VARCHAR(255) DEFAULT NULL, CHANGE updeted_at updeted_at DATETIME DEFAULT NULL, CHANGE last_message_at last_message_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE matiere CHANGE structure structure JSON NOT NULL, CHANGE cover_image cover_image JSON NOT NULL');
        $this->addSql('ALTER TABLE message CHANGE updated_at updated_at DATETIME DEFAULT NULL, CHANGE deleted_at deleted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation CHANGE cancell_at cancell_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE seance CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE student CHANGE validation_status validation_status VARCHAR(255) DEFAULT NULL, CHANGE certifications certifications JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE user CHANGE roles roles JSON NOT NULL');
        $this->addSql('ALTER TABLE messenger_messages CHANGE delivered_at delivered_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE blog CHANGE images images LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`, CHANGE published_at published_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE conversation DROP is_archived, CHANGE title title VARCHAR(255) DEFAULT \'NULL\', CHANGE updeted_at updeted_at DATETIME DEFAULT \'NULL\', CHANGE last_message_at last_message_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE matiere CHANGE structure structure LONGTEXT NOT NULL COLLATE `utf8mb4_bin`, CHANGE cover_image cover_image LONGTEXT NOT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE message CHANGE updated_at updated_at DATETIME DEFAULT \'NULL\', CHANGE deleted_at deleted_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE messenger_messages CHANGE delivered_at delivered_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE reservation CHANGE cancell_at cancell_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE seance CHANGE updated_at updated_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE student CHANGE validation_status validation_status VARCHAR(255) DEFAULT \'NULL\', CHANGE certifications certifications LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE `user` CHANGE roles roles LONGTEXT NOT NULL COLLATE `utf8mb4_bin`');
    }
}
