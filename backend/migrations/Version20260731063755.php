<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260731063755 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add owner-scoped media objects, variants, and typed attachments.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE media_attachment (id UUID NOT NULL, type VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, media_id UUID NOT NULL, user_id UUID DEFAULT NULL, person_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_737A172FEA9FDD75 ON media_attachment (media_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_737A172FA76ED395 ON media_attachment (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_737A172F217BBB47 ON media_attachment (person_id)');
        $this->addSql("ALTER TABLE media_attachment ADD CONSTRAINT chk_media_attachment_owner CHECK ((type = 'avatar' AND user_id IS NOT NULL AND person_id IS NULL) OR (type = 'person_photo' AND user_id IS NULL AND person_id IS NOT NULL))");
        $this->addSql('CREATE TABLE media_object (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_14D431327E3C61F9 ON media_object (owner_id)');
        $this->addSql('CREATE INDEX idx_media_owner_created ON media_object (owner_id, created_at)');
        $this->addSql('CREATE TABLE media_variant (id UUID NOT NULL, type VARCHAR(255) NOT NULL, storage_path VARCHAR(255) NOT NULL, mime_type VARCHAR(50) NOT NULL, width INT NOT NULL, height INT NOT NULL, byte_size INT NOT NULL, media_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_909439E4279401A ON media_variant (storage_path)');
        $this->addSql('CREATE INDEX IDX_909439E4EA9FDD75 ON media_variant (media_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_media_variant_type ON media_variant (media_id, type)');
        $this->addSql("ALTER TABLE media_variant ADD CONSTRAINT chk_media_variant_type CHECK (type IN ('avatar', 'full', 'portrait', 'thumbnail'))");
        $this->addSql('ALTER TABLE media_variant ADD CONSTRAINT chk_media_variant_dimensions CHECK (width > 0 AND height > 0 AND byte_size > 0)');
        $this->addSql('ALTER TABLE media_attachment ADD CONSTRAINT FK_737A172FEA9FDD75 FOREIGN KEY (media_id) REFERENCES media_object (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE media_attachment ADD CONSTRAINT FK_737A172FA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE media_attachment ADD CONSTRAINT FK_737A172F217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE media_object ADD CONSTRAINT FK_14D431327E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE media_variant ADD CONSTRAINT FK_909439E4EA9FDD75 FOREIGN KEY (media_id) REFERENCES media_object (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE media_attachment DROP CONSTRAINT FK_737A172FEA9FDD75');
        $this->addSql('ALTER TABLE media_attachment DROP CONSTRAINT FK_737A172FA76ED395');
        $this->addSql('ALTER TABLE media_attachment DROP CONSTRAINT FK_737A172F217BBB47');
        $this->addSql('ALTER TABLE media_object DROP CONSTRAINT FK_14D431327E3C61F9');
        $this->addSql('ALTER TABLE media_variant DROP CONSTRAINT FK_909439E4EA9FDD75');
        $this->addSql('DROP TABLE media_attachment');
        $this->addSql('DROP TABLE media_object');
        $this->addSql('DROP TABLE media_variant');
    }
}
