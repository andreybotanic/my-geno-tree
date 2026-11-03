<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261103092343 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE tree_merge_draft (id UUID NOT NULL, version INT NOT NULL, source_version INT NOT NULL, state VARCHAR(30) NOT NULL, messages JSON NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, tree_etag VARCHAR(100) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, tree_id UUID NOT NULL, reference_person_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_10867C917E3C61F9 ON tree_merge_draft (owner_id)');
        $this->addSql('CREATE INDEX IDX_10867C9178B64A2 ON tree_merge_draft (tree_id)');
        $this->addSql('CREATE INDEX IDX_10867C91E119DDB1 ON tree_merge_draft (reference_person_id)');
        $this->addSql('CREATE INDEX idx_merge_tree_updated ON tree_merge_draft (tree_id, updated_at)');
        $this->addSql('ALTER TABLE tree_merge_draft ADD CONSTRAINT FK_10867C917E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tree_merge_draft ADD CONSTRAINT FK_10867C9178B64A2 FOREIGN KEY (tree_id) REFERENCES family_tree (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tree_merge_draft ADD CONSTRAINT FK_10867C91E119DDB1 FOREIGN KEY (reference_person_id) REFERENCES person (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tree_merge_draft DROP CONSTRAINT FK_10867C917E3C61F9');
        $this->addSql('ALTER TABLE tree_merge_draft DROP CONSTRAINT FK_10867C9178B64A2');
        $this->addSql('ALTER TABLE tree_merge_draft DROP CONSTRAINT FK_10867C91E119DDB1');
        $this->addSql('DROP TABLE tree_merge_draft');
    }
}
