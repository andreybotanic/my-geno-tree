<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105123927 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep only conversation concurrency data in SQL; proposals share the Symfony message-store TTL';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_merge_draft RENAME TO tree_merge_conversation');
        $this->addSql('ALTER TABLE tree_merge_conversation DROP proposal, DROP result, DROP state');
        $this->addSql('ALTER INDEX uniq_10867c9178b64a2 RENAME TO UNIQ_838B292878B64A2');
        $this->addSql('ALTER INDEX idx_10867c91e119ddb1 RENAME TO IDX_838B2928E119DDB1');
        $this->addSql('ALTER TABLE tree_merge_conversation RENAME CONSTRAINT fk_10867c9178b64a2 TO FK_838B292878B64A2');
        $this->addSql('ALTER TABLE tree_merge_conversation RENAME CONSTRAINT fk_10867c91e119ddb1 TO FK_838B2928E119DDB1');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Expired SQL proposals cannot be reconstructed from ephemeral message history.');
    }
}
