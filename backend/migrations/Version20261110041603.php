<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110041603 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional titles and descriptions to tree documents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_document ADD title VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE tree_document ADD description VARCHAR(2000) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_document DROP title');
        $this->addSql('ALTER TABLE tree_document DROP description');
    }
}
