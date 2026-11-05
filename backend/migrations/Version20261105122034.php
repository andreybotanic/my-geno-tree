<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105122034 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bind the single tree draft lifetime to its ephemeral conversation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_merge_draft ADD chat_session_id UUID DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_merge_draft DROP chat_session_id');
    }
}
