<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261103175416 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove persisted merge conversations and audio attachments';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql("DELETE FROM media_object WHERE id IN (SELECT media_id FROM media_variant WHERE type IN ('audio_source', 'audio_normalized'))");
        $this->addSql('ALTER TABLE media_attachment DROP CONSTRAINT chk_media_attachment_owner');
        $this->addSql("ALTER TABLE media_attachment ADD CONSTRAINT chk_media_attachment_owner CHECK ((type = 'avatar' AND user_id IS NOT NULL AND person_id IS NULL) OR (type = 'person_photo' AND user_id IS NULL AND person_id IS NOT NULL))");
        $this->addSql('ALTER TABLE media_variant DROP CONSTRAINT chk_media_variant_type');
        $this->addSql("ALTER TABLE media_variant ADD CONSTRAINT chk_media_variant_type CHECK (type IN ('avatar', 'full', 'portrait', 'thumbnail'))");
        $this->addSql('ALTER TABLE media_variant DROP CONSTRAINT chk_media_variant_dimensions');
        $this->addSql('ALTER TABLE media_variant ADD CONSTRAINT chk_media_variant_dimensions CHECK (width > 0 AND height > 0 AND byte_size > 0)');
        $this->addSql('ALTER TABLE media_attachment DROP CONSTRAINT fk_737a172f552a2803');
        $this->addSql('DROP INDEX idx_737a172f552a2803');
        $this->addSql('ALTER TABLE media_attachment DROP merge_draft_id');
        $this->addSql('ALTER TABLE media_variant ALTER width SET NOT NULL');
        $this->addSql('ALTER TABLE media_variant ALTER height SET NOT NULL');
        $this->addSql('ALTER TABLE tree_merge_draft DROP messages');
        $this->addSql('ALTER TABLE tree_merge_draft DROP audio_sources');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Session-only conversations and deleted recordings cannot be restored.');
    }
}
