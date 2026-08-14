<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store up to ten derived ordinary-tree layouts per canonical reference anchor.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tree_layout (id UUID NOT NULL, tree_id UUID NOT NULL, primary_person_id UUID NOT NULL, secondary_person_id UUID DEFAULT NULL, layout_revision VARCHAR(43) NOT NULL, algorithm_version INT NOT NULL, positions JSONB NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_tree_layout_lru ON tree_layout (tree_id, last_used_at, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tree_layout_anchor ON tree_layout (tree_id, primary_person_id, secondary_person_id) NULLS NOT DISTINCT');
        $this->addSql('ALTER TABLE tree_layout ADD CONSTRAINT fk_tree_layout_tree FOREIGN KEY (tree_id) REFERENCES family_tree (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE tree_layout ADD CONSTRAINT fk_tree_layout_primary_person FOREIGN KEY (primary_person_id) REFERENCES person (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE tree_layout ADD CONSTRAINT fk_tree_layout_secondary_person FOREIGN KEY (secondary_person_id) REFERENCES person (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tree_layout');
    }
}
