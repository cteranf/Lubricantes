<?php

namespace Tests\Support;

use Illuminate\Database\Query\Builder;

final class PaymentLockSqlObserver
{
    public static function builderRequestsUpdateLock(array $frames): bool
    {
        foreach ($frames as $frame) {
            if (($frame['function'] ?? null) === 'runSelect' && ($frame['object'] ?? null) instanceof Builder) {
                return $frame['object']->lock === true;
            }
        }

        return false;
    }

    public static function tables(string $sql, string $driver, bool $builderForUpdate = false): array
    {
        // Tokenize rather than searching normalized SQL text: literals and
        // comments must never become keywords, identifiers or lock clauses.
        $pattern = <<<'SQL'
~(?<space>\s+)|(?<comment>--[^\r\n]*|\#[^\r\n]*|/\*.*?\*/)|(?<literal>'(?:''|\\.|[^'\\])*')|(?<quoted>"(?:""|[^"])*"|`(?:``|[^`])*`|\[(?:\]\]|[^\]])*\])|(?<word>[a-zA-Z_][a-zA-Z0-9_$]*)|(?<symbol>.)~s
SQL;
        preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $tokens = [];
        foreach ($matches as $match) {
            if ($match['space'] !== null || $match['comment'] !== null) {
                continue;
            }
            if ($match['literal'] !== null) {
                $tokens[] = ['literal', ''];
            } elseif ($match['quoted'] !== null) {
                $identifier = substr($match['quoted'], 1, -1);
                $tokens[] = ['identifier', strtolower(str_replace(['""', '``', ']]'], ['"', '`', ']'], $identifier))];
            } elseif ($match['word'] !== null) {
                $tokens[] = ['word', strtolower($match['word'])];
            } else {
                $tokens[] = ['symbol', $match['symbol']];
            }
        }
        if (($tokens[0] ?? null) !== ['word', 'select']) {
            return [];
        }
        $depth = 0;
        $hasUpdateLock = false;
        foreach ($tokens as $index => $token) {
            if ($token === ['symbol', '(']) {
                $depth++;
            } elseif ($token === ['symbol', ')']) {
                $depth--;
            } elseif ($depth === 0 && $token === ['word', 'for'] && ($tokens[$index + 1] ?? null) === ['word', 'update']) {
                $hasUpdateLock = true;
            }
        }
        // SQLite's grammar erases boolean locks. Its SQL alone cannot prove
        // intent; accept it only with the executing builder's lock=true flag.
        if (! (($driver === 'mysql' && $hasUpdateLock) || ($driver === 'sqlite' && $builderForUpdate))) {
            return [];
        }
        $tables = [];
        $depth = 0;
        foreach ($tokens as $index => $token) {
            if ($token === ['symbol', '(']) {
                $depth++;
            } elseif ($token === ['symbol', ')']) {
                $depth--;
            } elseif ($depth === 0 && in_array($token, [['word', 'from'], ['word', 'join']], true)) {
                $cursor = $index + 1;
                $name = null;
                while (isset($tokens[$cursor]) && in_array($tokens[$cursor][0], ['word', 'identifier'], true)) {
                    $name = $tokens[$cursor][1];
                    if (($tokens[$cursor + 1] ?? null) !== ['symbol', '.']) {
                        break;
                    }
                    $cursor += 2;
                    $name = null;
                }
                if (in_array($name, ['orders', 'payment_preferences'], true)) {
                    $tables[] = $name;
                }
            }
        }

        return $tables;
    }
}
