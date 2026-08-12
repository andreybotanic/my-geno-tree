<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260812110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Set the indexable strict-word trigram prefilter threshold for the application role.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER ROLE CURRENT_USER SET pg_trgm.strict_word_similarity_threshold TO '0.2'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER ROLE CURRENT_USER RESET pg_trgm.strict_word_similarity_threshold');
    }
}
