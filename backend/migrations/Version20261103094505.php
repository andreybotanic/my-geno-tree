<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261103094505 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Versioned merge proposals and atomic application receipts';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tree_merge_draft ADD proposal_version INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE tree_merge_draft ALTER proposal_version DROP DEFAULT');
        $this->addSql('ALTER TABLE tree_merge_draft ADD prepared_source_version INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tree_merge_draft ADD proposal JSON DEFAULT NULL');
        $this->addSql("ALTER TABLE tree_merge_draft ADD corrections JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE tree_merge_draft ALTER corrections DROP DEFAULT');
        $this->addSql("ALTER TABLE tree_merge_draft ADD questions JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE tree_merge_draft ALTER questions DROP DEFAULT');
        $this->addSql('ALTER TABLE tree_merge_draft ADD result JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tree_merge_draft DROP proposal_version');
        $this->addSql('ALTER TABLE tree_merge_draft DROP prepared_source_version');
        $this->addSql('ALTER TABLE tree_merge_draft DROP proposal');
        $this->addSql('ALTER TABLE tree_merge_draft DROP corrections');
        $this->addSql('ALTER TABLE tree_merge_draft DROP questions');
        $this->addSql('ALTER TABLE tree_merge_draft DROP result');
    }
}
