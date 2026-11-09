<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109124800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store document originals independently of image media without rewriting files.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_document DROP CONSTRAINT fk_bba5610dea9fdd75');
        $this->addSql('DROP INDEX uniq_bba5610dea9fdd75');
        $this->addSql('ALTER TABLE tree_document ADD storage_path VARCHAR(255) DEFAULT NULL');
        $this->addSql("UPDATE tree_document d SET storage_path = v.storage_path FROM media_variant v WHERE v.media_id = d.media_id AND v.type = 'original'");
        $this->addSql('ALTER TABLE tree_document ALTER storage_path SET NOT NULL');
        $this->addSql("DELETE FROM media_variant v USING tree_document d WHERE v.media_id = d.media_id AND v.type = 'original'");
        $this->addSql('DELETE FROM media_object m USING tree_document d WHERE m.id = d.media_id AND NOT EXISTS (SELECT 1 FROM media_variant v WHERE v.media_id = m.id)');
        $this->addSql('ALTER TABLE tree_document DROP media_id');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BBA5610D279401A ON tree_document (storage_path)');
        $this->addSql('ALTER TABLE media_variant DROP CONSTRAINT chk_media_variant_type');
        $this->addSql("ALTER TABLE media_variant ADD CONSTRAINT chk_media_variant_type CHECK (type IN ('avatar', 'full', 'portrait', 'thumbnail'))");
        $this->addSql('ALTER TABLE media_variant DROP CONSTRAINT chk_media_variant_dimensions');
        $this->addSql('ALTER TABLE media_variant ADD CONSTRAINT chk_media_variant_dimensions CHECK (width > 0 AND height > 0 AND byte_size > 0)');
        $this->addSql('ALTER TABLE media_variant ALTER width SET NOT NULL');
        $this->addSql('ALTER TABLE media_variant ALTER height SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_variant ALTER width DROP NOT NULL');
        $this->addSql('ALTER TABLE media_variant ALTER height DROP NOT NULL');
        $this->addSql('ALTER TABLE media_variant DROP CONSTRAINT chk_media_variant_type');
        $this->addSql("ALTER TABLE media_variant ADD CONSTRAINT chk_media_variant_type CHECK (type IN ('avatar', 'full', 'portrait', 'thumbnail', 'original'))");
        $this->addSql('ALTER TABLE media_variant DROP CONSTRAINT chk_media_variant_dimensions');
        $this->addSql("ALTER TABLE media_variant ADD CONSTRAINT chk_media_variant_dimensions CHECK (byte_size > 0 AND ((type = 'original' AND width IS NULL AND height IS NULL) OR (type <> 'original' AND width IS NOT NULL AND height IS NOT NULL AND width > 0 AND height > 0)))");
        $this->addSql('DROP INDEX UNIQ_BBA5610D279401A');
        $this->addSql('ALTER TABLE tree_document ADD media_id UUID DEFAULT NULL');
        $this->addSql("INSERT INTO media_object (id, owner_id, created_at) SELECT d.id, COALESCE(t.owner_id, CASE WHEN d.storage_path LIKE 'documents/%' THEN split_part(d.storage_path, '/', 2)::uuid ELSE split_part(d.storage_path, '/', 1)::uuid END), d.created_at FROM tree_document d LEFT JOIN family_tree t ON t.id = d.tree_id");
        $this->addSql("INSERT INTO media_variant (id, media_id, type, storage_path, mime_type, width, height, byte_size) SELECT id, id, 'original', storage_path, mime_type, NULL, NULL, original_size_bytes FROM tree_document");
        $this->addSql('UPDATE tree_document SET media_id = id');
        $this->addSql('ALTER TABLE tree_document ALTER media_id SET NOT NULL');
        $this->addSql('ALTER TABLE tree_document DROP storage_path');
        $this->addSql('ALTER TABLE tree_document ADD CONSTRAINT fk_bba5610dea9fdd75 FOREIGN KEY (media_id) REFERENCES media_object (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX uniq_bba5610dea9fdd75 ON tree_document (media_id)');
    }
}
