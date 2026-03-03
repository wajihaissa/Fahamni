<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260225030851 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flashcard_attempt ADD section_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE flashcard_attempt ADD CONSTRAINT FK_8E45002DD823E37A FOREIGN KEY (section_id) REFERENCES section (id)');
        $this->addSql('CREATE INDEX IDX_8E45002DD823E37A ON flashcard_attempt (section_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flashcard_attempt DROP FOREIGN KEY FK_8E45002DD823E37A');
        $this->addSql('DROP INDEX IDX_8E45002DD823E37A ON flashcard_attempt');
        $this->addSql('ALTER TABLE flashcard_attempt DROP section_id');
    }
}
