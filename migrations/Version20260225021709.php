<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260225021709 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE blog (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(255) NOT NULL, content LONGTEXT NOT NULL, images JSON DEFAULT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, published_at DATETIME DEFAULT NULL, publisher_id INT NOT NULL, INDEX IDX_C015514340C86FCE (publisher_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE chapter (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(255) NOT NULL, matiere_id INT NOT NULL, INDEX IDX_F981B52EF46CD258 (matiere_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE choice (id INT AUTO_INCREMENT NOT NULL, choice LONGTEXT NOT NULL, is_correct TINYINT NOT NULL, question_id INT NOT NULL, INDEX IDX_C1AB5A921E27F6BF (question_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE conversation (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) DEFAULT NULL, is_group TINYINT NOT NULL, created_at DATETIME NOT NULL, updeted_at DATETIME DEFAULT NULL, is_deleted TINYINT NOT NULL, is_archived TINYINT DEFAULT 0 NOT NULL, last_message_at DATETIME DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_8A8E26E9B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE conversation_user (conversation_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_5AECB5559AC0396 (conversation_id), INDEX IDX_5AECB555A76ED395 (user_id), PRIMARY KEY (conversation_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE conversation_report (id INT AUTO_INCREMENT NOT NULL, reason VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, conversation_id INT NOT NULL, reported_by_id INT NOT NULL, INDEX IDX_F6E3CD449AC0396 (conversation_id), INDEX IDX_F6E3CD4471CE806 (reported_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE flashcard_attempt (id INT AUTO_INCREMENT NOT NULL, question VARCHAR(255) NOT NULL, user_answer VARCHAR(255) NOT NULL, ai_feedback VARCHAR(255) NOT NULL, is_correct TINYINT NOT NULL, subject_id INT NOT NULL, INDEX IDX_8E45002D23EDC87 (subject_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE interaction (id INT AUTO_INCREMENT NOT NULL, reaction INT DEFAULT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, innteractor_id INT NOT NULL, blog_id INT NOT NULL, INDEX IDX_378DFDA714DE4863 (innteractor_id), INDEX IDX_378DFDA7DAE07E97 (blog_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE matiere (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, structure JSON NOT NULL, created_at DATETIME NOT NULL, cover_image VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE matiere_category (matiere_id INT NOT NULL, category_id INT NOT NULL, INDEX IDX_B2248179F46CD258 (matiere_id), INDEX IDX_B224817912469DE2 (category_id), PRIMARY KEY (matiere_id, category_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE message (id INT AUTO_INCREMENT NOT NULL, content LONGTEXT NOT NULL, is_read TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, conversation_id INT NOT NULL, sender_id INT NOT NULL, reply_to_id INT DEFAULT NULL, INDEX IDX_B6BD307F9AC0396 (conversation_id), INDEX IDX_B6BD307FF624B39D (sender_id), INDEX IDX_B6BD307FFFDF7169 (reply_to_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE message_user (message_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_24064D90537A1329 (message_id), INDEX IDX_24064D90A76ED395 (user_id), PRIMARY KEY (message_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE message_reaction (id INT AUTO_INCREMENT NOT NULL, emoji INT NOT NULL, reactor_id INT NOT NULL, message_id INT NOT NULL, INDEX IDX_ADF1C3E6723AD41B (reactor_id), INDEX IDX_ADF1C3E6537A1329 (message_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE message_report (id INT AUTO_INCREMENT NOT NULL, reason VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, message_id INT NOT NULL, reported_by_id INT NOT NULL, INDEX IDX_F308EA8B537A1329 (message_id), INDEX IDX_F308EA8B71CE806 (reported_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE question (id INT AUTO_INCREMENT NOT NULL, question LONGTEXT NOT NULL, quiz_id INT NOT NULL, INDEX IDX_B6F7494E853CD175 (quiz_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(255) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation (id INT AUTO_INCREMENT NOT NULL, status INT NOT NULL, reserved_at DATETIME NOT NULL, cancell_at DATETIME DEFAULT NULL, notes LONGTEXT DEFAULT NULL, seance_id INT NOT NULL, participant_id INT NOT NULL, INDEX IDX_42C84955E3797A94 (seance_id), INDEX IDX_42C849559D1C3019 (participant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE resource (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, filepath VARCHAR(255) DEFAULT NULL, link VARCHAR(255) DEFAULT NULL, section_id INT NOT NULL, INDEX IDX_BC91F416D823E37A (section_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE seance (id INT AUTO_INCREMENT NOT NULL, matiere VARCHAR(100) NOT NULL, start_at DATETIME NOT NULL, duration_min INT NOT NULL, max_participants INT NOT NULL, status INT NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, tuteur_id INT NOT NULL, INDEX IDX_DF7DFD0E86EC68D8 (tuteur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE section (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(255) NOT NULL, chapter_id INT NOT NULL, INDEX IDX_2D737AEF579F4768 (chapter_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE student (id INT AUTO_INCREMENT NOT NULL, roles VARCHAR(255) NOT NULL, bio LONGTEXT DEFAULT NULL, phone INT NOT NULL, validation_status VARCHAR(255) DEFAULT NULL, is_active TINYINT DEFAULT NULL, certifications JSON DEFAULT NULL, user_id_id INT NOT NULL, UNIQUE INDEX UNIQ_B723AF339D86650F (user_id_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, status TINYINT DEFAULT NULL, created_at DATETIME NOT NULL, full_name VARCHAR(255) NOT NULL, conversation_nicknames JSON DEFAULT NULL, UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE blog ADD CONSTRAINT FK_C015514340C86FCE FOREIGN KEY (publisher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE chapter ADD CONSTRAINT FK_F981B52EF46CD258 FOREIGN KEY (matiere_id) REFERENCES matiere (id)');
        $this->addSql('ALTER TABLE choice ADD CONSTRAINT FK_C1AB5A921E27F6BF FOREIGN KEY (question_id) REFERENCES question (id)');
        $this->addSql('ALTER TABLE conversation ADD CONSTRAINT FK_8A8E26E9B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE conversation_user ADD CONSTRAINT FK_5AECB5559AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversation_user ADD CONSTRAINT FK_5AECB555A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversation_report ADD CONSTRAINT FK_F6E3CD449AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id)');
        $this->addSql('ALTER TABLE conversation_report ADD CONSTRAINT FK_F6E3CD4471CE806 FOREIGN KEY (reported_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE flashcard_attempt ADD CONSTRAINT FK_8E45002D23EDC87 FOREIGN KEY (subject_id) REFERENCES matiere (id)');
        $this->addSql('ALTER TABLE interaction ADD CONSTRAINT FK_378DFDA714DE4863 FOREIGN KEY (innteractor_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE interaction ADD CONSTRAINT FK_378DFDA7DAE07E97 FOREIGN KEY (blog_id) REFERENCES blog (id)');
        $this->addSql('ALTER TABLE matiere_category ADD CONSTRAINT FK_B2248179F46CD258 FOREIGN KEY (matiere_id) REFERENCES matiere (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE matiere_category ADD CONSTRAINT FK_B224817912469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307F9AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id)');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307FF624B39D FOREIGN KEY (sender_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307FFFDF7169 FOREIGN KEY (reply_to_id) REFERENCES message (id)');
        $this->addSql('ALTER TABLE message_user ADD CONSTRAINT FK_24064D90537A1329 FOREIGN KEY (message_id) REFERENCES message (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message_user ADD CONSTRAINT FK_24064D90A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message_reaction ADD CONSTRAINT FK_ADF1C3E6723AD41B FOREIGN KEY (reactor_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE message_reaction ADD CONSTRAINT FK_ADF1C3E6537A1329 FOREIGN KEY (message_id) REFERENCES message (id)');
        $this->addSql('ALTER TABLE message_report ADD CONSTRAINT FK_F308EA8B537A1329 FOREIGN KEY (message_id) REFERENCES message (id)');
        $this->addSql('ALTER TABLE message_report ADD CONSTRAINT FK_F308EA8B71CE806 FOREIGN KEY (reported_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE question ADD CONSTRAINT FK_B6F7494E853CD175 FOREIGN KEY (quiz_id) REFERENCES quiz (id)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C84955E3797A94 FOREIGN KEY (seance_id) REFERENCES seance (id)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C849559D1C3019 FOREIGN KEY (participant_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE resource ADD CONSTRAINT FK_BC91F416D823E37A FOREIGN KEY (section_id) REFERENCES section (id)');
        $this->addSql('ALTER TABLE seance ADD CONSTRAINT FK_DF7DFD0E86EC68D8 FOREIGN KEY (tuteur_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE section ADD CONSTRAINT FK_2D737AEF579F4768 FOREIGN KEY (chapter_id) REFERENCES chapter (id)');
        $this->addSql('ALTER TABLE student ADD CONSTRAINT FK_B723AF339D86650F FOREIGN KEY (user_id_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE blog DROP FOREIGN KEY FK_C015514340C86FCE');
        $this->addSql('ALTER TABLE chapter DROP FOREIGN KEY FK_F981B52EF46CD258');
        $this->addSql('ALTER TABLE choice DROP FOREIGN KEY FK_C1AB5A921E27F6BF');
        $this->addSql('ALTER TABLE conversation DROP FOREIGN KEY FK_8A8E26E9B03A8386');
        $this->addSql('ALTER TABLE conversation_user DROP FOREIGN KEY FK_5AECB5559AC0396');
        $this->addSql('ALTER TABLE conversation_user DROP FOREIGN KEY FK_5AECB555A76ED395');
        $this->addSql('ALTER TABLE conversation_report DROP FOREIGN KEY FK_F6E3CD449AC0396');
        $this->addSql('ALTER TABLE conversation_report DROP FOREIGN KEY FK_F6E3CD4471CE806');
        $this->addSql('ALTER TABLE flashcard_attempt DROP FOREIGN KEY FK_8E45002D23EDC87');
        $this->addSql('ALTER TABLE interaction DROP FOREIGN KEY FK_378DFDA714DE4863');
        $this->addSql('ALTER TABLE interaction DROP FOREIGN KEY FK_378DFDA7DAE07E97');
        $this->addSql('ALTER TABLE matiere_category DROP FOREIGN KEY FK_B2248179F46CD258');
        $this->addSql('ALTER TABLE matiere_category DROP FOREIGN KEY FK_B224817912469DE2');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307F9AC0396');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307FF624B39D');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307FFFDF7169');
        $this->addSql('ALTER TABLE message_user DROP FOREIGN KEY FK_24064D90537A1329');
        $this->addSql('ALTER TABLE message_user DROP FOREIGN KEY FK_24064D90A76ED395');
        $this->addSql('ALTER TABLE message_reaction DROP FOREIGN KEY FK_ADF1C3E6723AD41B');
        $this->addSql('ALTER TABLE message_reaction DROP FOREIGN KEY FK_ADF1C3E6537A1329');
        $this->addSql('ALTER TABLE message_report DROP FOREIGN KEY FK_F308EA8B537A1329');
        $this->addSql('ALTER TABLE message_report DROP FOREIGN KEY FK_F308EA8B71CE806');
        $this->addSql('ALTER TABLE question DROP FOREIGN KEY FK_B6F7494E853CD175');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C84955E3797A94');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C849559D1C3019');
        $this->addSql('ALTER TABLE resource DROP FOREIGN KEY FK_BC91F416D823E37A');
        $this->addSql('ALTER TABLE seance DROP FOREIGN KEY FK_DF7DFD0E86EC68D8');
        $this->addSql('ALTER TABLE section DROP FOREIGN KEY FK_2D737AEF579F4768');
        $this->addSql('ALTER TABLE student DROP FOREIGN KEY FK_B723AF339D86650F');
        $this->addSql('DROP TABLE blog');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE chapter');
        $this->addSql('DROP TABLE choice');
        $this->addSql('DROP TABLE conversation');
        $this->addSql('DROP TABLE conversation_user');
        $this->addSql('DROP TABLE conversation_report');
        $this->addSql('DROP TABLE flashcard_attempt');
        $this->addSql('DROP TABLE interaction');
        $this->addSql('DROP TABLE matiere');
        $this->addSql('DROP TABLE matiere_category');
        $this->addSql('DROP TABLE message');
        $this->addSql('DROP TABLE message_user');
        $this->addSql('DROP TABLE message_reaction');
        $this->addSql('DROP TABLE message_report');
        $this->addSql('DROP TABLE question');
        $this->addSql('DROP TABLE quiz');
        $this->addSql('DROP TABLE reservation');
        $this->addSql('DROP TABLE resource');
        $this->addSql('DROP TABLE seance');
        $this->addSql('DROP TABLE section');
        $this->addSql('DROP TABLE student');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
