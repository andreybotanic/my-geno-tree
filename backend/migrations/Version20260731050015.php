<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260731050015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create people and genealogy events with structured dates.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE genealogical_event (id UUID NOT NULL, type VARCHAR(255) NOT NULL, source VARCHAR(255) NOT NULL, title VARCHAR(200) DEFAULT NULL, description TEXT DEFAULT NULL, data JSON NOT NULL, source_entity_type VARCHAR(50) DEFAULT NULL, source_entity_id UUID DEFAULT NULL, manual_order INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, event_date_state VARCHAR(255) NOT NULL, event_date_precision VARCHAR(255) DEFAULT NULL, event_date_year INT DEFAULT NULL, event_date_month INT DEFAULT NULL, event_date_day INT DEFAULT NULL, event_date_approximate BOOLEAN NOT NULL, person_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_30621870217BBB47 ON genealogical_event (person_id)');
        $this->addSql('CREATE INDEX idx_event_person_created ON genealogical_event (person_id, created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_automatic_event_source ON genealogical_event (person_id, type, source_entity_id)');
        $this->addSql('CREATE TABLE person (id UUID NOT NULL, gender VARCHAR(255) NOT NULL, deceased BOOLEAN NOT NULL, x DOUBLE PRECISION NOT NULL, y DOUBLE PRECISION NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, birth_surname_state VARCHAR(255) NOT NULL, birth_surname_value VARCHAR(100) DEFAULT NULL, given_name_state VARCHAR(255) NOT NULL, given_name_value VARCHAR(100) DEFAULT NULL, patronymic_state VARCHAR(255) NOT NULL, patronymic_value VARCHAR(100) DEFAULT NULL, birth_date_state VARCHAR(255) NOT NULL, birth_date_precision VARCHAR(255) DEFAULT NULL, birth_date_year INT DEFAULT NULL, birth_date_month INT DEFAULT NULL, birth_date_day INT DEFAULT NULL, birth_date_approximate BOOLEAN NOT NULL, death_date_state VARCHAR(255) NOT NULL, death_date_precision VARCHAR(255) DEFAULT NULL, death_date_year INT DEFAULT NULL, death_date_month INT DEFAULT NULL, death_date_day INT DEFAULT NULL, death_date_approximate BOOLEAN NOT NULL, tree_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_34DCD17678B64A2 ON person (tree_id)');
        $this->addSql('CREATE INDEX idx_person_tree_created ON person (tree_id, created_at)');
        $this->addSql('ALTER TABLE genealogical_event ADD CONSTRAINT FK_30621870217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE person ADD CONSTRAINT FK_34DCD17678B64A2 FOREIGN KEY (tree_id) REFERENCES family_tree (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE genealogical_event DROP CONSTRAINT FK_30621870217BBB47');
        $this->addSql('ALTER TABLE person DROP CONSTRAINT FK_34DCD17678B64A2');
        $this->addSql('DROP TABLE genealogical_event');
        $this->addSql('DROP TABLE person');
    }
}
