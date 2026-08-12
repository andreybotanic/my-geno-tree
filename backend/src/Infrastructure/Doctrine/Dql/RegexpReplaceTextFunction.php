<?php

namespace App\Infrastructure\Doctrine\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

final class RegexpReplaceTextFunction extends FunctionNode
{
    private Node $value;
    private Node $pattern;
    private Node $replacement;
    private Node $flags;

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            'regexp_replace(%s, %s, %s, %s)',
            $sqlWalker->walkStringPrimary($this->value),
            $sqlWalker->walkStringPrimary($this->pattern),
            $sqlWalker->walkStringPrimary($this->replacement),
            $sqlWalker->walkStringPrimary($this->flags),
        );
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->value = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->pattern = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->replacement = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->flags = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
