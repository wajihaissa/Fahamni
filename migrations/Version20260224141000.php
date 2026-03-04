<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260224141000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de la table in_app_notification (notifications paiement en temps reel)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE in_app_notification (id INT AUTO_INCREMENT NOT NULL, recipient_id INT NOT NULL, type VARCHAR(60) NOT NULL, event_key VARCHAR(191) NOT NULL, title VARCHAR(180) NOT NULL, message LONGTEXT NOT NULL, data JSON DEFAULT NULL, is_read TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', read_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_notif_recipient_read_created (recipient_id, is_read, created_at), UNIQUE INDEX uniq_notif_recipient_event (recipient_id, event_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE in_app_notification ADD CONSTRAINT FK_8FFDA93EE92F8F78 FOREIGN KEY (recipient_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE in_app_notification DROP FOREIGN KEY FK_8FFDA93EE92F8F78');
        $this->addSql('DROP TABLE in_app_notification');
    }
}
