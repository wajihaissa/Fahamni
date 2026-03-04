<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de la table payment_transaction pour Stripe Checkout';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payment_transaction (id INT AUTO_INCREMENT NOT NULL, reservation_id INT NOT NULL, stripe_checkout_session_id VARCHAR(191) NOT NULL, stripe_payment_intent_id VARCHAR(191) DEFAULT NULL, amount_cents INT NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(30) NOT NULL, payment_method_type VARCHAR(64) DEFAULT NULL, student_email VARCHAR(180) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', paid_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', error_message LONGTEXT DEFAULT NULL, metadata JSON DEFAULT NULL, INDEX IDX_64A8A327B83297E7 (reservation_id), UNIQUE INDEX uniq_payment_checkout_session (stripe_checkout_session_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE payment_transaction ADD CONSTRAINT FK_64A8A327B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_transaction DROP FOREIGN KEY FK_64A8A327B83297E7');
        $this->addSql('DROP TABLE payment_transaction');
    }
}

