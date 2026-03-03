<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de la table revision_planner (historique planner + progression)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE revision_planner (id INT AUTO_INCREMENT NOT NULL, student_id INT NOT NULL, exam_date DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', focus_subject VARCHAR(150) DEFAULT NULL, difficulty_level VARCHAR(20) NOT NULL, daily_sessions INT NOT NULL, include_weekend TINYINT(1) NOT NULL, reminder_time VARCHAR(5) NOT NULL, plan_data JSON NOT NULL, progress_data JSON DEFAULT NULL, total_entries INT NOT NULL, completed_entries INT NOT NULL, completion_rate DOUBLE PRECISION NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_2E56E46A76ED395 (student_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE revision_planner ADD CONSTRAINT FK_2E56E46A76ED395 FOREIGN KEY (student_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE revision_planner DROP FOREIGN KEY FK_2E56E46A76ED395');
        $this->addSql('DROP TABLE revision_planner');
    }
}

