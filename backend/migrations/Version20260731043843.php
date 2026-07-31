<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260731043843 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create owner-scoped versioned family trees.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE family_tree (id UUID NOT NULL, name VARCHAR(100) NOT NULL, version INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C349FCF87E3C61F9 ON family_tree (owner_id)');
        $this->addSql('CREATE INDEX idx_tree_owner_updated ON family_tree (owner_id, updated_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_family_tree_owner_name ON family_tree (owner_id, name)');
        $this->addSql('ALTER TABLE family_tree ADD CONSTRAINT FK_C349FCF87E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE family_tree DROP CONSTRAINT FK_C349FCF87E3C61F9');
        $this->addSql('DROP TABLE family_tree');
    }
}
