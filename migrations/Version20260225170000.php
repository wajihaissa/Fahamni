<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260225170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Face++ enrollment fields to user table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user`
            ADD COLUMN IF NOT EXISTS face_id_enabled TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS face_id_token VARCHAR(255) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS face_id_enrolled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user`
            DROP COLUMN face_id_enabled,
            DROP COLUMN face_id_token,
            DROP COLUMN face_id_enrolled_at');
    }
}

