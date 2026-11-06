<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261106075908 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Support CIMD URL client identifiers and index normalized tree titles.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE oauth2_access_token ALTER client TYPE VARCHAR(2048)');
        $this->addSql('ALTER TABLE oauth2_authorization_code ALTER client TYPE VARCHAR(2048)');
        $this->addSql('ALTER TABLE oauth2_client ALTER name TYPE VARCHAR(200)');
        $this->addSql('ALTER TABLE oauth2_client ALTER identifier TYPE VARCHAR(2048)');
        $this->addSql("CREATE INDEX idx_tree_normalized_name_trgm ON family_tree USING gin (trim(regexp_replace(lower(translate(name, 'Ёё', 'Ее')), '[^[:alnum:]]+', ' ', 'g')) gin_trgm_ops)");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_tree_normalized_name_trgm');
        $this->addSql('ALTER TABLE oauth2_access_token ALTER client TYPE VARCHAR(32)');
        $this->addSql('ALTER TABLE oauth2_authorization_code ALTER client TYPE VARCHAR(32)');
        $this->addSql('ALTER TABLE oauth2_client ALTER name TYPE VARCHAR(128)');
        $this->addSql('ALTER TABLE oauth2_client ALTER identifier TYPE VARCHAR(32)');
    }
}
