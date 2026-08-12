<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260812100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable fuzzy name search extensions and add its trigram expression index.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS fuzzystrmatch');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_person_tree_normalized_name_trgm
            ON person USING gin (
              trim(regexp_replace(lower(translate(COALESCE(birth_surname_value, '') || ' ' || COALESCE(given_name_value, '') || ' ' || COALESCE(patronymic_value, ''), 'Ёё', 'Ее')), '[^[:alnum:]]+', ' ', 'g')) gin_trgm_ops
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_person_tree_normalized_name_trgm');
        $this->addSql('DROP EXTENSION IF EXISTS pg_trgm');
        $this->addSql('DROP EXTENSION IF EXISTS fuzzystrmatch');
    }
}
