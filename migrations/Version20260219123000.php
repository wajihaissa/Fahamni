<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260219123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout des champs anti-doublon email sur reservation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD confirmation_email_sent_at DATETIME DEFAULT NULL, ADD acceptance_email_sent_at DATETIME DEFAULT NULL, ADD reminder_email_sent_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation DROP confirmation_email_sent_at, DROP acceptance_email_sent_at, DROP reminder_email_sent_at');
    }
}

