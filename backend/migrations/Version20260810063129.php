<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260810063129 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reusable places to genealogical events and their automatic sources';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE event_place (id UUID NOT NULL, name VARCHAR(500) NOT NULL, latitude NUMERIC(10, 7) DEFAULT NULL, longitude NUMERIC(10, 7) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE event_place ADD CONSTRAINT chk_event_place_coordinate_pair CHECK ((latitude IS NULL) = (longitude IS NULL))');
        $this->addSql('ALTER TABLE event_place ADD CONSTRAINT chk_event_place_latitude CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90)');
        $this->addSql('ALTER TABLE event_place ADD CONSTRAINT chk_event_place_longitude CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180)');
        $this->addSql('ALTER TABLE genealogical_event ADD place_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE genealogical_event ADD CONSTRAINT FK_30621870DA6A219 FOREIGN KEY (place_id) REFERENCES event_place (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_30621870DA6A219 ON genealogical_event (place_id)');
        $this->addSql('ALTER TABLE marriage_period ADD marriage_place_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE marriage_period ADD dissolution_place_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE marriage_period ADD CONSTRAINT FK_7EBA2F812B994AEB FOREIGN KEY (marriage_place_id) REFERENCES event_place (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE marriage_period ADD CONSTRAINT FK_7EBA2F8117446506 FOREIGN KEY (dissolution_place_id) REFERENCES event_place (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_7EBA2F812B994AEB ON marriage_period (marriage_place_id)');
        $this->addSql('CREATE INDEX IDX_7EBA2F8117446506 ON marriage_period (dissolution_place_id)');
        $this->addSql('ALTER TABLE person ADD birth_place_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE person ADD death_place_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE person ADD CONSTRAINT FK_34DCD176B4BB6BBC FOREIGN KEY (birth_place_id) REFERENCES event_place (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE person ADD CONSTRAINT FK_34DCD176AD45A6FD FOREIGN KEY (death_place_id) REFERENCES event_place (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_34DCD176B4BB6BBC ON person (birth_place_id)');
        $this->addSql('CREATE INDEX IDX_34DCD176AD45A6FD ON person (death_place_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE genealogical_event DROP CONSTRAINT FK_30621870DA6A219');
        $this->addSql('DROP INDEX IDX_30621870DA6A219');
        $this->addSql('ALTER TABLE genealogical_event DROP place_id');
        $this->addSql('ALTER TABLE marriage_period DROP CONSTRAINT FK_7EBA2F812B994AEB');
        $this->addSql('ALTER TABLE marriage_period DROP CONSTRAINT FK_7EBA2F8117446506');
        $this->addSql('DROP INDEX IDX_7EBA2F812B994AEB');
        $this->addSql('DROP INDEX IDX_7EBA2F8117446506');
        $this->addSql('ALTER TABLE marriage_period DROP marriage_place_id');
        $this->addSql('ALTER TABLE marriage_period DROP dissolution_place_id');
        $this->addSql('ALTER TABLE person DROP CONSTRAINT FK_34DCD176B4BB6BBC');
        $this->addSql('ALTER TABLE person DROP CONSTRAINT FK_34DCD176AD45A6FD');
        $this->addSql('DROP INDEX IDX_34DCD176B4BB6BBC');
        $this->addSql('DROP INDEX IDX_34DCD176AD45A6FD');
        $this->addSql('ALTER TABLE person DROP birth_place_id');
        $this->addSql('ALTER TABLE person DROP death_place_id');
        $this->addSql('DROP TABLE event_place');
    }
}
