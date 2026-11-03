<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103101906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track merge inference attempts and preserve the state preceding a conversational request';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_merge_draft ADD attempt_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE tree_merge_draft ADD attempt_source_version INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tree_merge_draft ADD attempt_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE tree_merge_draft ADD state_before_attempt VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_merge_draft DROP attempt_id');
        $this->addSql('ALTER TABLE tree_merge_draft DROP attempt_source_version');
        $this->addSql('ALTER TABLE tree_merge_draft DROP attempt_started_at');
        $this->addSql('ALTER TABLE tree_merge_draft DROP state_before_attempt');
    }
}
