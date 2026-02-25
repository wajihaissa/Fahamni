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
        $userTable = $schema->getTable('user');

        if (!$userTable->hasColumn('passkey_credential_id')) {
            $this->addSql('ALTER TABLE `user` ADD passkey_credential_id VARCHAR(255) DEFAULT NULL');
        }
        if (!$userTable->hasColumn('passkey_public_key_pem')) {
            $this->addSql('ALTER TABLE `user` ADD passkey_public_key_pem LONGTEXT DEFAULT NULL');
        }
        if (!$userTable->hasColumn('passkey_sign_count')) {
            $this->addSql('ALTER TABLE `user` ADD passkey_sign_count INT DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $userTable = $schema->getTable('user');

        if ($userTable->hasColumn('passkey_credential_id')) {
            $this->addSql('ALTER TABLE `user` DROP passkey_credential_id');
        }
        if ($userTable->hasColumn('passkey_public_key_pem')) {
            $this->addSql('ALTER TABLE `user` DROP passkey_public_key_pem');
        }
        if ($userTable->hasColumn('passkey_sign_count')) {
            $this->addSql('ALTER TABLE `user` DROP passkey_sign_count');
        }
    }
}

