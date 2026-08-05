<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805055905 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store a person or married pair as the tree reference and remove persisted card coordinates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE family_tree ADD reference_primary_person_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE family_tree ADD reference_secondary_person_id UUID DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE family_tree AS tree
            SET reference_primary_person_id = (
                SELECT person.id
                FROM person
                WHERE person.tree_id = tree.id
                ORDER BY person.created_at ASC, person.id ASC
                LIMIT 1
            )
            SQL);
        $this->addSql('ALTER TABLE family_tree ADD CONSTRAINT FK_C349FCF896AB2438 FOREIGN KEY (reference_primary_person_id) REFERENCES person (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE family_tree ADD CONSTRAINT FK_C349FCF8D2A7C283 FOREIGN KEY (reference_secondary_person_id) REFERENCES person (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_C349FCF896AB2438 ON family_tree (reference_primary_person_id)');
        $this->addSql('CREATE INDEX IDX_C349FCF8D2A7C283 ON family_tree (reference_secondary_person_id)');
        $this->addSql('ALTER TABLE person DROP x');
        $this->addSql('ALTER TABLE person DROP y');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE family_tree DROP CONSTRAINT FK_C349FCF896AB2438');
        $this->addSql('ALTER TABLE family_tree DROP CONSTRAINT FK_C349FCF8D2A7C283');
        $this->addSql('DROP INDEX IDX_C349FCF896AB2438');
        $this->addSql('DROP INDEX IDX_C349FCF8D2A7C283');
        $this->addSql('ALTER TABLE family_tree DROP reference_primary_person_id');
        $this->addSql('ALTER TABLE family_tree DROP reference_secondary_person_id');
        $this->addSql('ALTER TABLE person ADD x DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE person ADD y DOUBLE PRECISION DEFAULT 0 NOT NULL');
    }
}
