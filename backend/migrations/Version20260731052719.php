<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260731052719 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add parent links and marriage periods to family trees.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE marriage_period (id UUID NOT NULL, dissolved BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, marriage_date_state VARCHAR(255) NOT NULL, marriage_date_precision VARCHAR(255) DEFAULT NULL, marriage_date_year INT DEFAULT NULL, marriage_date_month INT DEFAULT NULL, marriage_date_day INT DEFAULT NULL, marriage_date_approximate BOOLEAN NOT NULL, dissolution_date_state VARCHAR(255) NOT NULL, dissolution_date_precision VARCHAR(255) DEFAULT NULL, dissolution_date_year INT DEFAULT NULL, dissolution_date_month INT DEFAULT NULL, dissolution_date_day INT DEFAULT NULL, dissolution_date_approximate BOOLEAN NOT NULL, tree_id UUID NOT NULL, male_person_id UUID NOT NULL, female_person_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7EBA2F81BA4FECB1 ON marriage_period (male_person_id)');
        $this->addSql('CREATE INDEX IDX_7EBA2F819C26C128 ON marriage_period (female_person_id)');
        $this->addSql('CREATE INDEX idx_marriage_period_tree ON marriage_period (tree_id)');
        $this->addSql('CREATE INDEX idx_marriage_period_pair ON marriage_period (male_person_id, female_person_id)');
        $this->addSql('CREATE TABLE parent_link (id UUID NOT NULL, role VARCHAR(255) NOT NULL, tree_id UUID NOT NULL, parent_id UUID NOT NULL, child_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B4FAF45727ACA70 ON parent_link (parent_id)');
        $this->addSql('CREATE INDEX IDX_B4FAF45DD62C21B ON parent_link (child_id)');
        $this->addSql('CREATE INDEX idx_parent_link_tree ON parent_link (tree_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_parent_link_child_role ON parent_link (child_id, role)');
        $this->addSql('CREATE UNIQUE INDEX uniq_parent_link_pair ON parent_link (parent_id, child_id)');
        $this->addSql('ALTER TABLE marriage_period ADD CONSTRAINT FK_7EBA2F8178B64A2 FOREIGN KEY (tree_id) REFERENCES family_tree (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE marriage_period ADD CONSTRAINT FK_7EBA2F81BA4FECB1 FOREIGN KEY (male_person_id) REFERENCES person (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE marriage_period ADD CONSTRAINT FK_7EBA2F819C26C128 FOREIGN KEY (female_person_id) REFERENCES person (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE parent_link ADD CONSTRAINT FK_B4FAF4578B64A2 FOREIGN KEY (tree_id) REFERENCES family_tree (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE parent_link ADD CONSTRAINT FK_B4FAF45727ACA70 FOREIGN KEY (parent_id) REFERENCES person (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE parent_link ADD CONSTRAINT FK_B4FAF45DD62C21B FOREIGN KEY (child_id) REFERENCES person (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE marriage_period DROP CONSTRAINT FK_7EBA2F8178B64A2');
        $this->addSql('ALTER TABLE marriage_period DROP CONSTRAINT FK_7EBA2F81BA4FECB1');
        $this->addSql('ALTER TABLE marriage_period DROP CONSTRAINT FK_7EBA2F819C26C128');
        $this->addSql('ALTER TABLE parent_link DROP CONSTRAINT FK_B4FAF4578B64A2');
        $this->addSql('ALTER TABLE parent_link DROP CONSTRAINT FK_B4FAF45727ACA70');
        $this->addSql('ALTER TABLE parent_link DROP CONSTRAINT FK_B4FAF45DD62C21B');
        $this->addSql('DROP TABLE marriage_period');
        $this->addSql('DROP TABLE parent_link');
    }
}
