<?php

namespace App\Portals\Finance;

use Illuminate\Database\Query\Builder;

/** Translate legacy field selectors to ordinary source columns during the cutover. */
trait FinanceRelationalGrammar
{
    private array $financeAliases = [];
    private ?string $financeDefault = null;

    private function financeContext(Builder $query, callable $compile): mixed
    {
        $previous = [$this->financeAliases, $this->financeDefault];
        $register = function (mixed $table, bool $default = false): void {
            if ($table instanceof \Illuminate\Contracts\Database\Query\Expression) {
                $sql = $table->getValue($this);
                if (preg_match('/\bas\s+[`"]?([a-z_]+)[`"]?$/i', $sql, $match)) {
                    $alias = $match[1];
                    $this->financeAliases[$alias] = preg_match('/\b(channel_flags|source_hash)\b/', $sql) && ! preg_match('/\bas\s+[`"]?payload\b/i', $sql) ? 'record' : null;
                    if ($default) $this->financeDefault = $alias;
                }
                return;
            }
            if (! is_string($table)) return;
            if (! preg_match('/^([a-z_]+)(?:\s+(?:as\s+)?([a-z_]+))?$/iD', $table, $match)) return;
            $base = $match[1]; $alias = $match[2] ?? $base;
            $kind = match ($base) { 'finance_records', 'finance_transactions' => 'record',
                'finance_payment_transactions' => 'payment', 'finance_credit_settlement_entries' => 'statement', default => null };
            $this->financeAliases[$alias] = $kind;
            if ($default) $this->financeDefault = $alias;
        };
        $register($query->from, true);
        foreach ($query->joins ?? [] as $join) $register($join->table);
        try { return $compile(); } finally { [$this->financeAliases, $this->financeDefault] = $previous; }
    }

    public function compileSelect(Builder $query)
    {
        return $this->financeContext($query, fn () => parent::compileSelect($query));
    }

    public function compileWheres(Builder $query)
    {
        return $this->financeContext($query, fn () => parent::compileWheres($query));
    }

    public function wrapForFinanceQuery($query, string $selector): string
    {
        if ($query instanceof \Illuminate\Database\Eloquent\Builder) $query = $query->getQuery();
        return $this->financeContext($query, fn () => $this->wrap($selector));
    }

    private function financeSelector(string $selector): ?string
    {
        $parts = explode('->', $selector); $column = array_shift($parts);
        if ($parts === []) return null;
        $segments = explode('.', $column); $field = array_pop($segments);
        if (! in_array($field, ['payload', 'raw_payload'], true)) return null;
        $alias = $segments === [] ? $this->financeDefault : implode('.', $segments);
        $kind = array_key_exists($alias ?? '', $this->financeAliases) ? $this->financeAliases[$alias] : match ($alias) {
            'finance_records', 'finance_transactions' => 'record', 'finance_payment_transactions' => 'payment',
            'finance_credit_settlement_entries' => 'statement', default => null,
        };
        if ($kind === null || ($field === 'raw_payload') !== ($kind === 'payment')) return null;
        $storage = app(FinanceSourceStorage::class);
        if (! $storage->active($kind)) return null;
        return $storage->expression($kind, $alias ?? '', $parts)->getValue($this);
    }

    protected function wrapJsonSelector($value)
    {
        return $this->financeSelector($value) ?? parent::wrapJsonSelector($value);
    }

    protected function wrapJsonBooleanSelector($value)
    {
        return $this->financeSelector($value) ?? parent::wrapJsonBooleanSelector($value);
    }

    protected function whereNull(Builder $query, $where)
    {
        $column = $where['column'];
        if (is_string($column) && ($mapped = $this->financeSelector($column)) !== null) return $mapped.' is null';
        return parent::whereNull($query, $where);
    }

    protected function whereNotNull(Builder $query, $where)
    {
        $column = $where['column'];
        if (is_string($column) && ($mapped = $this->financeSelector($column)) !== null) return $mapped.' is not null';
        return parent::whereNotNull($query, $where);
    }
}
