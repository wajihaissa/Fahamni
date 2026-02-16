<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260211000434 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE conversation_report (id INT AUTO_INCREMENT NOT NULL, reason VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, conversation_id INT NOT NULL, reported_by_id INT NOT NULL, INDEX IDX_F6E3CD449AC0396 (conversation_id), INDEX IDX_F6E3CD4471CE806 (reported_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE message_report (id INT AUTO_INCREMENT NOT NULL, reason VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, message_id INT NOT NULL, reported_by_id INT NOT NULL, INDEX IDX_F308EA8B537A1329 (message_id), INDEX IDX_F308EA8B71CE806 (reported_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE conversation_report ADD CONSTRAINT FK_F6E3CD449AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id)');
        $this->addSql('ALTER TABLE conversation_report ADD CONSTRAINT FK_F6E3CD4471CE806 FOREIGN KEY (reported_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE message_report ADD CONSTRAINT FK_F308EA8B537A1329 FOREIGN KEY (message_id) REFERENCES message (id)');
        $this->addSql('ALTER TABLE message_report ADD CONSTRAINT FK_F308EA8B71CE806 FOREIGN KEY (reported_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE blog CHANGE images images JSON DEFAULT NULL, CHANGE published_at published_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE conversation CHANGE title title VARCHAR(255) DEFAULT NULL, CHANGE updeted_at updeted_at DATETIME DEFAULT NULL, CHANGE last_message_at last_message_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE matiere CHANGE structure structure JSON NOT NULL, CHANGE cover_image cover_image JSON NOT NULL');
        $this->addSql('ALTER TABLE message CHANGE updated_at updated_at DATETIME DEFAULT NULL, CHANGE deleted_at deleted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation CHANGE cancell_at cancell_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE seance CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE student CHANGE validation_status validation_status VARCHAR(255) DEFAULT NULL, CHANGE certifications certifications JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE user CHANGE roles roles JSON NOT NULL, CHANGE conversation_nicknames conversation_nicknames JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE messenger_messages CHANGE delivered_at delivered_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE conversation_report DROP FOREIGN KEY FK_F6E3CD449AC0396');
        $this->addSql('ALTER TABLE conversation_report DROP FOREIGN KEY FK_F6E3CD4471CE806');
        $this->addSql('ALTER TABLE message_report DROP FOREIGN KEY FK_F308EA8B537A1329');
        $this->addSql('ALTER TABLE message_report DROP FOREIGN KEY FK_F308EA8B71CE806');
        $this->addSql('DROP TABLE conversation_report');
        $this->addSql('DROP TABLE message_report');
        $this->addSql('ALTER TABLE blog CHANGE images images LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`, CHANGE published_at published_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE conversation CHANGE title title VARCHAR(255) DEFAULT \'NULL\', CHANGE updeted_at updeted_at DATETIME DEFAULT \'NULL\', CHANGE last_message_at last_message_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE matiere CHANGE structure structure LONGTEXT NOT NULL COLLATE `utf8mb4_bin`, CHANGE cover_image cover_image LONGTEXT NOT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE message CHANGE updated_at updated_at DATETIME DEFAULT \'NULL\', CHANGE deleted_at deleted_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE messenger_messages CHANGE delivered_at delivered_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE reservation CHANGE cancell_at cancell_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE seance CHANGE updated_at updated_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE student CHANGE validation_status validation_status VARCHAR(255) DEFAULT \'NULL\', CHANGE certifications certifications LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE `user` CHANGE roles roles LONGTEXT NOT NULL COLLATE `utf8mb4_bin`, CHANGE conversation_nicknames conversation_nicknames LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
    }
}
