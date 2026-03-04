<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260224170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add created_at timestamp to quiz for admin statistics';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `quiz` ADD created_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE `quiz` SET created_at = NOW() WHERE created_at IS NULL');
        $this->addSql('ALTER TABLE `quiz` CHANGE created_at created_at DATETIME NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `quiz` DROP created_at');
    }
}
