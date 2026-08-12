<?php

namespace App\Infrastructure\Doctrine\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

final class TranslateTextFunction extends FunctionNode
{
    private Node $value;
    private Node $from;
    private Node $to;

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            'translate(%s, %s, %s)',
            $sqlWalker->walkStringPrimary($this->value),
            $sqlWalker->walkStringPrimary($this->from),
            $sqlWalker->walkStringPrimary($this->to),
        );
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->value = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->from = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->to = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
