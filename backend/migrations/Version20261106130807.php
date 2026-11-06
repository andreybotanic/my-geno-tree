<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106130807 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add persistent AI chat sanctions and a separate suspicious-message journal.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE chat_ban (id UUID NOT NULL, charged_tokens INT NOT NULL, number ' .
                'INT NOT NULL, starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ends_at ' .
                'TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY ' .
                '(id))',
        );
        $this->addSql('CREATE INDEX IDX_4234F640A76ED395 ON chat_ban (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4234F640A76ED39596901F54 ON chat_ban (user_id, number)');
        $this->addSql(
            'CREATE TABLE suspicious_chat_message (id UUID NOT NULL, operation_key ' .
                'VARCHAR(64) NOT NULL, content TEXT NOT NULL, risk DOUBLE PRECISION NOT NULL, ' .
                'warning_number INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT ' .
                'NULL, user_id UUID NOT NULL, PRIMARY KEY (id))',
        );
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2F95203F95B1BD95 ON suspicious_chat_message (operation_key)');
        $this->addSql('CREATE INDEX IDX_2F95203FA76ED395 ON suspicious_chat_message (user_id)');
        $this->addSql('CREATE INDEX IDX_2F95203F8B8E8428 ON suspicious_chat_message (created_at)');
        $this->addSql(
            'ALTER TABLE chat_ban ADD CONSTRAINT FK_4234F640A76ED395 FOREIGN KEY (user_id) ' .
                'REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE',
        );
        $this->addSql(
            'ALTER TABLE suspicious_chat_message ADD CONSTRAINT FK_2F95203FA76ED395 FOREIGN ' .
                'KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE',
        );
        $this->addSql('ALTER TABLE app_user ADD chat_warnings INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE app_user ADD last_chat_ban_id UUID DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE app_user ADD CONSTRAINT FK_88BDF3E935BF8530 FOREIGN KEY ' .
                '(last_chat_ban_id) REFERENCES chat_ban (id) ON DELETE SET NULL NOT DEFERRABLE',
        );
        $this->addSql('CREATE INDEX IDX_88BDF3E935BF8530 ON app_user (last_chat_ban_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_ban DROP CONSTRAINT FK_4234F640A76ED395');
        $this->addSql('ALTER TABLE suspicious_chat_message DROP CONSTRAINT FK_2F95203FA76ED395');
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT FK_88BDF3E935BF8530');
        $this->addSql('DROP TABLE chat_ban');
        $this->addSql('DROP TABLE suspicious_chat_message');
        $this->addSql('DROP INDEX IDX_88BDF3E935BF8530');
        $this->addSql('ALTER TABLE app_user DROP chat_warnings');
        $this->addSql('ALTER TABLE app_user DROP last_chat_ban_id');
    }
}
