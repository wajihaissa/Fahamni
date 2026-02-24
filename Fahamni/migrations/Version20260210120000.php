<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260210120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout colonne conversation_nicknames (JSON) sur user pour surnoms par conversation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD conversation_nicknames JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP conversation_nicknames');
    }
}
