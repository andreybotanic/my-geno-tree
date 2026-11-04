<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261103114700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Attach normalized voice recordings and transcription attempts to tree merge drafts';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE media_attachment ADD merge_draft_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE media_attachment ADD CONSTRAINT FK_737A172F552A2803 FOREIGN KEY (merge_draft_id) REFERENCES tree_merge_draft (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_737A172F552A2803 ON media_attachment (merge_draft_id)');
        $this->addSql('ALTER TABLE media_variant ALTER width DROP NOT NULL');
        $this->addSql('ALTER TABLE media_variant ALTER height DROP NOT NULL');
        $this->addSql('ALTER TABLE tree_merge_draft ADD audio_sources JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE media_attachment DROP CONSTRAINT chk_media_attachment_owner');
        $this->addSql("ALTER TABLE media_attachment ADD CONSTRAINT chk_media_attachment_owner CHECK ((type = 'avatar' AND user_id IS NOT NULL AND person_id IS NULL AND merge_draft_id IS NULL) OR (type = 'person_photo' AND user_id IS NULL AND person_id IS NOT NULL AND merge_draft_id IS NULL) OR (type = 'tree_merge_audio' AND user_id IS NULL AND person_id IS NULL AND merge_draft_id IS NOT NULL))");
        $this->addSql('ALTER TABLE media_variant DROP CONSTRAINT chk_media_variant_type');
        $this->addSql("ALTER TABLE media_variant ADD CONSTRAINT chk_media_variant_type CHECK (type IN ('avatar', 'full', 'portrait', 'thumbnail', 'audio_source', 'audio_normalized'))");
        $this->addSql('ALTER TABLE media_variant DROP CONSTRAINT chk_media_variant_dimensions');
        $this->addSql("ALTER TABLE media_variant ADD CONSTRAINT chk_media_variant_dimensions CHECK (byte_size > 0 AND ((type IN ('audio_source', 'audio_normalized') AND width IS NULL AND height IS NULL) OR (type IN ('avatar', 'full', 'portrait', 'thumbnail') AND width IS NOT NULL AND height IS NOT NULL AND width > 0 AND height > 0)))");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE media_attachment DROP CONSTRAINT chk_media_attachment_owner');
        $this->addSql("ALTER TABLE media_attachment ADD CONSTRAINT chk_media_attachment_owner CHECK ((type = 'avatar' AND user_id IS NOT NULL AND person_id IS NULL) OR (type = 'person_photo' AND user_id IS NULL AND person_id IS NOT NULL))");
        $this->addSql('ALTER TABLE media_variant DROP CONSTRAINT chk_media_variant_type');
        $this->addSql("ALTER TABLE media_variant ADD CONSTRAINT chk_media_variant_type CHECK (type IN ('avatar', 'full', 'portrait', 'thumbnail'))");
        $this->addSql('ALTER TABLE media_variant DROP CONSTRAINT chk_media_variant_dimensions');
        $this->addSql('ALTER TABLE media_variant ADD CONSTRAINT chk_media_variant_dimensions CHECK (width > 0 AND height > 0 AND byte_size > 0)');
        $this->addSql('ALTER TABLE media_attachment DROP CONSTRAINT FK_737A172F552A2803');
        $this->addSql('DROP INDEX IDX_737A172F552A2803');
        $this->addSql('ALTER TABLE media_attachment DROP merge_draft_id');
        $this->addSql('ALTER TABLE media_variant ALTER width SET NOT NULL');
        $this->addSql('ALTER TABLE media_variant ALTER height SET NOT NULL');
        $this->addSql('ALTER TABLE tree_merge_draft DROP audio_sources');
    }
}
