<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105133203 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove SQL conversation coordination; chat state lives in Symfony AI MessageStore';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_merge_conversation DROP CONSTRAINT fk_838b292878b64a2');
        $this->addSql('ALTER TABLE tree_merge_conversation DROP CONSTRAINT fk_838b2928e119ddb1');
        $this->addSql('DROP TABLE tree_merge_conversation');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tree_merge_conversation (id UUID NOT NULL, version INT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, tree_etag VARCHAR(100) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, conversation_id UUID NOT NULL, tree_id UUID NOT NULL, reference_person_id UUID DEFAULT NULL, proposal_version INT NOT NULL, attempt_id VARCHAR(36) DEFAULT NULL, attempt_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, chat_state VARCHAR(20) NOT NULL, attempt_request_id VARCHAR(64) DEFAULT NULL, chat_session_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_838b2928e119ddb1 ON tree_merge_conversation (reference_person_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_838b292878b64a2 ON tree_merge_conversation (tree_id)');
        $this->addSql('ALTER TABLE tree_merge_conversation ADD CONSTRAINT fk_838b292878b64a2 FOREIGN KEY (tree_id) REFERENCES family_tree (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE tree_merge_conversation ADD CONSTRAINT fk_838b2928e119ddb1 FOREIGN KEY (reference_person_id) REFERENCES person (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
