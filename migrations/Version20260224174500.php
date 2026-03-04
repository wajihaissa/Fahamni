<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260224174500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add quiz keyword mapping and pending certification keywords on student';
    }

    public function up(Schema $schema): void
    {
        $quiz = $schema->getTable('quiz');
        if (!$quiz->hasColumn('keyword')) {
            $this->addSql('ALTER TABLE quiz ADD keyword VARCHAR(190) DEFAULT NULL');
        }
        if (!$quiz->hasIndex('UNIQ_A412FA9207BDA06')) {
            $this->addSql('CREATE UNIQUE INDEX UNIQ_A412FA9207BDA06 ON quiz (keyword)');
        }

        $student = $schema->getTable('student');
        if (!$student->hasColumn('certification_keywords')) {
            $this->addSql('ALTER TABLE student ADD certification_keywords JSON DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $quiz = $schema->getTable('quiz');
        if ($quiz->hasIndex('UNIQ_A412FA9207BDA06')) {
            $this->addSql('DROP INDEX UNIQ_A412FA9207BDA06 ON quiz');
        }
        if ($quiz->hasColumn('keyword')) {
            $this->addSql('ALTER TABLE quiz DROP keyword');
        }

        $student = $schema->getTable('student');
        if ($student->hasColumn('certification_keywords')) {
            $this->addSql('ALTER TABLE student DROP certification_keywords');
        }
    }
}
