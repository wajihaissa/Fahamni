<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260224163000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add two-factor authentication fields to user table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` 
            ADD COLUMN IF NOT EXISTS two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS two_factor_secret LONGTEXT DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS two_factor_recovery_codes JSON DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS two_factor_confirmed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user`
            DROP COLUMN two_factor_enabled,
            DROP COLUMN two_factor_secret,
            DROP COLUMN two_factor_recovery_codes,
            DROP COLUMN two_factor_confirmed_at');
    }
}

