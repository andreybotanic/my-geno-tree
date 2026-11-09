<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109092924 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add private tree documents, original byte sizes and asynchronous indexing state.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE tree_document (id UUID NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(50) NOT NULL, original_size_bytes INT NOT NULL, checksum VARCHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, generation INT NOT NULL, error_code VARCHAR(50) DEFAULT NULL, deleted BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, tree_id UUID DEFAULT NULL, person_id UUID DEFAULT NULL, media_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_BBA5610D78B64A2 ON tree_document (tree_id)');
        $this->addSql('CREATE INDEX IDX_BBA5610D217BBB47 ON tree_document (person_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BBA5610DEA9FDD75 ON tree_document (media_id)');
        $this->addSql('CREATE INDEX idx_document_tree_page ON tree_document (tree_id, id)');
        $this->addSql('ALTER TABLE tree_document ADD CONSTRAINT FK_BBA5610D78B64A2 FOREIGN KEY (tree_id) REFERENCES family_tree (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tree_document ADD CONSTRAINT FK_BBA5610D217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tree_document ADD CONSTRAINT FK_BBA5610DEA9FDD75 FOREIGN KEY (media_id) REFERENCES media_object (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE media_variant ALTER width DROP NOT NULL');
        $this->addSql('ALTER TABLE media_variant ALTER height DROP NOT NULL');
        $this->addSql('ALTER TABLE media_variant DROP CONSTRAINT chk_media_variant_type');
        $this->addSql("ALTER TABLE media_variant ADD CONSTRAINT chk_media_variant_type CHECK (type IN ('avatar', 'full', 'portrait', 'thumbnail', 'original'))");
        $this->addSql('ALTER TABLE media_variant DROP CONSTRAINT chk_media_variant_dimensions');
        $this->addSql("ALTER TABLE media_variant ADD CONSTRAINT chk_media_variant_dimensions CHECK (byte_size > 0 AND ((type = 'original' AND width IS NULL AND height IS NULL) OR (type <> 'original' AND width IS NOT NULL AND height IS NOT NULL AND width > 0 AND height > 0)))");
        $this->addSql("ALTER TABLE tree_document ADD CONSTRAINT chk_document_metadata CHECK (original_size_bytes BETWEEN 1 AND 10485760 AND generation > 0 AND status IN ('queued', 'processing', 'ready', 'failed') AND mime_type IN ('text/plain', 'text/markdown') AND (deleted OR tree_id IS NOT NULL))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM media_object WHERE id IN (SELECT media_id FROM media_variant WHERE type = 'original')");
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tree_document DROP CONSTRAINT FK_BBA5610D78B64A2');
        $this->addSql('ALTER TABLE tree_document DROP CONSTRAINT FK_BBA5610D217BBB47');
        $this->addSql('ALTER TABLE tree_document DROP CONSTRAINT FK_BBA5610DEA9FDD75');
        $this->addSql('DROP TABLE tree_document');
        $this->addSql('ALTER TABLE media_variant DROP CONSTRAINT chk_media_variant_type');
        $this->addSql("ALTER TABLE media_variant ADD CONSTRAINT chk_media_variant_type CHECK (type IN ('avatar', 'full', 'portrait', 'thumbnail'))");
        $this->addSql('ALTER TABLE media_variant DROP CONSTRAINT chk_media_variant_dimensions');
        $this->addSql('ALTER TABLE media_variant ADD CONSTRAINT chk_media_variant_dimensions CHECK (width > 0 AND height > 0 AND byte_size > 0)');
        $this->addSql('ALTER TABLE media_variant ALTER width SET NOT NULL');
        $this->addSql('ALTER TABLE media_variant ALTER height SET NOT NULL');
    }
}
