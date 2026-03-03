<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260219100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout table rating_tutor (etudiant -> tuteur)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE rating_tutor (id INT AUTO_INCREMENT NOT NULL, etudiant_id INT NOT NULL, tuteur_id INT NOT NULL, note INT NOT NULL, commentaire LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_8E9EE8FA3A4D5350 (etudiant_id), INDEX IDX_8E9EE8FA730E35D1 (tuteur_id), UNIQUE INDEX uniq_rating_tutor_pair (etudiant_id, tuteur_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE rating_tutor ADD CONSTRAINT FK_8E9EE8FA3A4D5350 FOREIGN KEY (etudiant_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE rating_tutor ADD CONSTRAINT FK_8E9EE8FA730E35D1 FOREIGN KEY (tuteur_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rating_tutor DROP FOREIGN KEY FK_8E9EE8FA3A4D5350');
        $this->addSql('ALTER TABLE rating_tutor DROP FOREIGN KEY FK_8E9EE8FA730E35D1');
        $this->addSql('DROP TABLE rating_tutor');
    }
}

