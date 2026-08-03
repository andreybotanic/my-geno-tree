<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803134707 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store a saved blood-network map state for each tree.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE family_tree ADD blood_network_state JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE family_tree DROP blood_network_state');
    }
}
