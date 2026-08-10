<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260810151851 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace date precision and approximate flag with DateKind and GEDCOM range end values.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE genealogical_event ADD event_date_end_year INT DEFAULT NULL');
        $this->addSql('ALTER TABLE genealogical_event ADD event_date_end_month INT DEFAULT NULL');
        $this->addSql('ALTER TABLE genealogical_event ADD event_date_end_day INT DEFAULT NULL');
        $this->addSql('ALTER TABLE genealogical_event ADD event_date_kind VARCHAR(255) DEFAULT NULL');
        $this->addSql("UPDATE genealogical_event SET event_date_kind = CASE WHEN event_date_state = 'known' AND event_date_approximate THEN 'approximate' WHEN event_date_state = 'known' THEN 'exact' ELSE NULL END");
        $this->addSql('ALTER TABLE genealogical_event DROP event_date_precision');
        $this->addSql('ALTER TABLE genealogical_event DROP event_date_approximate');
        $this->addSql('ALTER TABLE marriage_period ADD marriage_date_kind VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE marriage_period ADD marriage_date_end_year INT DEFAULT NULL');
        $this->addSql('ALTER TABLE marriage_period ADD marriage_date_end_month INT DEFAULT NULL');
        $this->addSql('ALTER TABLE marriage_period ADD marriage_date_end_day INT DEFAULT NULL');
        $this->addSql('ALTER TABLE marriage_period ADD dissolution_date_kind VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE marriage_period ADD dissolution_date_end_year INT DEFAULT NULL');
        $this->addSql('ALTER TABLE marriage_period ADD dissolution_date_end_month INT DEFAULT NULL');
        $this->addSql('ALTER TABLE marriage_period ADD dissolution_date_end_day INT DEFAULT NULL');
        $this->addSql("UPDATE marriage_period SET marriage_date_kind = CASE WHEN marriage_date_state = 'known' AND marriage_date_approximate THEN 'approximate' WHEN marriage_date_state = 'known' THEN 'exact' ELSE NULL END, dissolution_date_kind = CASE WHEN dissolution_date_state = 'known' AND dissolution_date_approximate THEN 'approximate' WHEN dissolution_date_state = 'known' THEN 'exact' ELSE NULL END");
        $this->addSql('ALTER TABLE marriage_period DROP marriage_date_precision');
        $this->addSql('ALTER TABLE marriage_period DROP marriage_date_approximate');
        $this->addSql('ALTER TABLE marriage_period DROP dissolution_date_precision');
        $this->addSql('ALTER TABLE marriage_period DROP dissolution_date_approximate');
        $this->addSql('ALTER TABLE person ADD birth_date_kind VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE person ADD birth_date_end_year INT DEFAULT NULL');
        $this->addSql('ALTER TABLE person ADD birth_date_end_month INT DEFAULT NULL');
        $this->addSql('ALTER TABLE person ADD birth_date_end_day INT DEFAULT NULL');
        $this->addSql('ALTER TABLE person ADD death_date_kind VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE person ADD death_date_end_year INT DEFAULT NULL');
        $this->addSql('ALTER TABLE person ADD death_date_end_month INT DEFAULT NULL');
        $this->addSql('ALTER TABLE person ADD death_date_end_day INT DEFAULT NULL');
        $this->addSql("UPDATE person SET birth_date_kind = CASE WHEN birth_date_state = 'known' AND birth_date_approximate THEN 'approximate' WHEN birth_date_state = 'known' THEN 'exact' ELSE NULL END, death_date_kind = CASE WHEN death_date_state = 'known' AND death_date_approximate THEN 'approximate' WHEN death_date_state = 'known' THEN 'exact' ELSE NULL END");
        $this->addSql('ALTER TABLE person DROP birth_date_precision');
        $this->addSql('ALTER TABLE person DROP birth_date_approximate');
        $this->addSql('ALTER TABLE person DROP death_date_precision');
        $this->addSql('ALTER TABLE person DROP death_date_approximate');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE genealogical_event ADD event_date_precision VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE genealogical_event ADD event_date_approximate BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql("UPDATE genealogical_event SET event_date_precision = CASE WHEN event_date_month IS NULL THEN 'year' WHEN event_date_day IS NULL THEN 'month' ELSE 'day' END, event_date_approximate = event_date_kind = 'approximate'");
        $this->addSql('ALTER TABLE genealogical_event DROP event_date_end_year');
        $this->addSql('ALTER TABLE genealogical_event DROP event_date_end_month');
        $this->addSql('ALTER TABLE genealogical_event DROP event_date_end_day');
        $this->addSql('ALTER TABLE genealogical_event DROP event_date_kind');
        $this->addSql('ALTER TABLE marriage_period ADD marriage_date_precision VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE marriage_period ADD marriage_date_approximate BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE marriage_period ADD dissolution_date_precision VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE marriage_period ADD dissolution_date_approximate BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql("UPDATE marriage_period SET marriage_date_precision = CASE WHEN marriage_date_month IS NULL THEN 'year' WHEN marriage_date_day IS NULL THEN 'month' ELSE 'day' END, marriage_date_approximate = marriage_date_kind = 'approximate', dissolution_date_precision = CASE WHEN dissolution_date_month IS NULL THEN 'year' WHEN dissolution_date_day IS NULL THEN 'month' ELSE 'day' END, dissolution_date_approximate = dissolution_date_kind = 'approximate'");
        $this->addSql('ALTER TABLE marriage_period DROP marriage_date_kind');
        $this->addSql('ALTER TABLE marriage_period DROP marriage_date_end_year');
        $this->addSql('ALTER TABLE marriage_period DROP marriage_date_end_month');
        $this->addSql('ALTER TABLE marriage_period DROP marriage_date_end_day');
        $this->addSql('ALTER TABLE marriage_period DROP dissolution_date_kind');
        $this->addSql('ALTER TABLE marriage_period DROP dissolution_date_end_year');
        $this->addSql('ALTER TABLE marriage_period DROP dissolution_date_end_month');
        $this->addSql('ALTER TABLE marriage_period DROP dissolution_date_end_day');
        $this->addSql('ALTER TABLE person ADD birth_date_precision VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE person ADD birth_date_approximate BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE person ADD death_date_precision VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE person ADD death_date_approximate BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql("UPDATE person SET birth_date_precision = CASE WHEN birth_date_month IS NULL THEN 'year' WHEN birth_date_day IS NULL THEN 'month' ELSE 'day' END, birth_date_approximate = birth_date_kind = 'approximate', death_date_precision = CASE WHEN death_date_month IS NULL THEN 'year' WHEN death_date_day IS NULL THEN 'month' ELSE 'day' END, death_date_approximate = death_date_kind = 'approximate'");
        $this->addSql('ALTER TABLE person DROP birth_date_kind');
        $this->addSql('ALTER TABLE person DROP birth_date_end_year');
        $this->addSql('ALTER TABLE person DROP birth_date_end_month');
        $this->addSql('ALTER TABLE person DROP birth_date_end_day');
        $this->addSql('ALTER TABLE person DROP death_date_kind');
        $this->addSql('ALTER TABLE person DROP death_date_end_year');
        $this->addSql('ALTER TABLE person DROP death_date_end_month');
        $this->addSql('ALTER TABLE person DROP death_date_end_day');
    }
}
