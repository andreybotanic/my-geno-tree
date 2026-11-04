<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103182425 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retain the merge source character budget without retaining chat messages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_merge_draft ADD source_length INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_merge_draft DROP source_length');
    }
}
