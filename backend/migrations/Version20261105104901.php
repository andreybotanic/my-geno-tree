<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105104901 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep one proposal per tree and separate agent attempts from proposal state';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM tree_merge_draft WHERE id IN (
                SELECT id FROM (
                    SELECT id, ROW_NUMBER() OVER (
                        PARTITION BY tree_id
                        ORDER BY (state NOT IN ('applied', 'cancelled')) DESC, updated_at DESC, id DESC
                    ) AS position FROM tree_merge_draft
                ) ranked WHERE position > 1
            )
            SQL);
        $this->addSql('ALTER TABLE tree_merge_draft DROP CONSTRAINT fk_10867c917e3c61f9');
        $this->addSql('DROP INDEX idx_merge_tree_updated');
        $this->addSql('DROP INDEX idx_10867c9178b64a2');
        $this->addSql('DROP INDEX idx_10867c917e3c61f9');
        $this->addSql("ALTER TABLE tree_merge_draft ADD chat_state VARCHAR(20) DEFAULT 'idle' NOT NULL");
        $this->addSql('ALTER TABLE tree_merge_draft ALTER chat_state DROP DEFAULT');
        $this->addSql('ALTER TABLE tree_merge_draft ADD attempt_request_id VARCHAR(64) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE tree_merge_draft SET
                state = CASE
                    WHEN state = 'cancelled' OR proposal IS NULL THEN 'collecting'
                    WHEN state IN ('processing', 'error') THEN
                        CASE WHEN json_array_length(proposal->'questions') > 0 THEN 'questions' ELSE 'ready' END
                    ELSE state
                END,
                proposal = CASE WHEN state = 'cancelled' THEN NULL ELSE proposal END,
                attempt_id = NULL, attempt_started_at = NULL, version = version + 1
            SQL);
        $this->addSql('ALTER TABLE tree_merge_draft DROP source_version');
        $this->addSql('ALTER TABLE tree_merge_draft DROP prepared_source_version');
        $this->addSql('ALTER TABLE tree_merge_draft DROP corrections');
        $this->addSql('ALTER TABLE tree_merge_draft DROP questions');
        $this->addSql('ALTER TABLE tree_merge_draft DROP attempt_source_version');
        $this->addSql('ALTER TABLE tree_merge_draft DROP state_before_attempt');
        $this->addSql('ALTER TABLE tree_merge_draft DROP source_length');
        $this->addSql('ALTER TABLE tree_merge_draft RENAME COLUMN owner_id TO conversation_id');
        $this->addSql('UPDATE tree_merge_draft SET conversation_id = id');
        foreach (['people', 'parents', 'marriages'] as $group) {
            $this->addSql(<<<SQL
                UPDATE tree_merge_draft SET proposal = jsonb_set(proposal::jsonb, ARRAY['{$group}'], (
                    SELECT COALESCE(jsonb_agg(jsonb_set(entry - 'candidates', '{evidence}', (
                        SELECT COALESCE(jsonb_agg(jsonb_set(evidence, '{quote}',
                            to_jsonb(LEFT(evidence->>'quote', 300)))), '[]'::jsonb)
                        FROM jsonb_array_elements(entry->'evidence') evidence
                    ))), '[]'::jsonb)
                    FROM jsonb_array_elements(proposal::jsonb->'{$group}') entry
                ))::json WHERE proposal IS NOT NULL
                SQL);
        }
        $this->addSql('CREATE UNIQUE INDEX UNIQ_10867C9178B64A2 ON tree_merge_draft (tree_id)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Obsolete drafts and correction histories cannot be restored.');
    }
}
