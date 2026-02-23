<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260221113152 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add passkey fields to user table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD passkey_credential_id VARCHAR(255) DEFAULT NULL, ADD passkey_public_key_pem LONGTEXT DEFAULT NULL, ADD passkey_sign_count INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP passkey_credential_id, DROP passkey_public_key_pem, DROP passkey_sign_count');
    }
}

