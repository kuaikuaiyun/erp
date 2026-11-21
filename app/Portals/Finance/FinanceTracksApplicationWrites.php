<?php

namespace App\Portals\Finance;

use Closure;

trait FinanceTracksApplicationWrites
{
    private bool $financeTrackingQuery = false;
    private ?bool $financeTrackingEnabled = null;
    private ?FinanceApplicationChanges $financeChangeObserver = null;
    private string|int|null $financeInsertId = null;

    public function financeInsertId(): string|int|null { return $this->financeInsertId; }

    protected function run($query, $bindings, Closure $callback)
    {
        if (str_contains($query, FinanceApplicationChanges::TABLE)) {
            $this->financeTrackingEnabled = null;
            foreach (['finance.application-tracking:', 'finance.scoped-tracking:'] as $prefix) {
                app('request')->attributes->remove($prefix.$this->getName().':'.$this->getDatabaseName());
            }
        }
        $run = function () use ($query, $bindings, $callback) {
            $result = parent::run($query, $bindings, $callback);
            if (preg_match('/^\s*(insert|replace)\b/i', $query) && ! $this->pretending()) $this->financeInsertId = $this->getPdo()->lastInsertId();
            return $result;
        };
        if ($this->financeTrackingQuery) return $run();
        $observer = $this->financeChangeObserver ??= new FinanceApplicationChanges;
        if (preg_match('/^\s*(?:alter|create|drop)\s+table\b/i', $query)) $observer->forgetColumns();
        $mutation = $observer->mutation($query);
        if ($mutation === null) return $run();
        if (! str_starts_with($mutation['table'], 'finance_')) {
            return $this->trackExternalFinanceSource($observer, $mutation, $query, $bindings, $run);
        }
        $this->financeTrackingQuery = true;
        try {
            $enabled = $this->financeTrackingEnabled ??= FinanceApplicationChanges::available($this);
            if (! $enabled) return $run();
            return $this->transaction(function () use ($observer, $mutation, $query, $bindings, $run) {
                $before = $observer->before($this, $query, $bindings, $mutation);
                $result = $run();
                $id = $this->financeInsertId;
                $mysqlId = $this instanceof \Illuminate\Database\MySqlConnection ? $this->getLastInsertId() : null;
                if ($before !== null) $observer->after($this, $before);
                $this->financeInsertId = $id;
                if ($this instanceof \Illuminate\Database\MySqlConnection) $this->lastInsertId = $mysqlId;
                return $result;
            }, 3);
        } finally { $this->financeTrackingQuery = false; }
    }

    private function trackExternalFinanceSource(FinanceApplicationChanges $observer, array $mutation, string $query, array $bindings, Closure $run)
    {
        $notifications = app(FinanceExternalChanges::class);
        try {
            $targets = $notifications->targets($this);
        } catch (\Throwable $error) { FinanceExternalChanges::failure($error); return $run(); }
        if (! $targets) return $run();
        // Observe only the source table. No finance SQL, new transaction, or FOR UPDATE lock belongs in this save.
        $this->financeTrackingQuery = true;
        try { $before = $observer->before($this, $query, $bindings, $mutation, false); }
        catch (\Throwable $error) { FinanceExternalChanges::failure($error); $before = [...$mutation, 'global' => true]; }
        finally { $this->financeTrackingQuery = false; }
        if ($before === null) return $run();
        $ticket = $notifications->begin($this);
        try { $result = $run(); }
        catch (\Throwable $error) { $notifications->discard($ticket); throw $error; }
        $events = [];
        $this->financeTrackingQuery = true;
        try {
            $observer->after($this, $before, function (string $source, array $values) use (&$events): void {
                $events[] = compact('source', 'values');
            });
        } catch (\Throwable $error) {
            FinanceExternalChanges::failure($error); $events = [['source' => 'scope_user', 'values' => []]];
        } finally { $this->financeTrackingQuery = false; }
        if ($this->transactionLevel() === 0) $notifications->commit($this, $ticket, $events);
        else {
            try {
                $this->afterCommit(fn () => $notifications->commit($this, $ticket, $events));
                $this->afterRollBack(fn () => $notifications->discard($ticket));
            } catch (\Throwable $error) {
                // Leave an unlocked pending record for conservative recovery on the finance side.
                if ($ticket !== null && is_resource($ticket['handle'])) fclose($ticket['handle']);
                FinanceExternalChanges::failure($error);
            }
        }
        return $result;
    }
}
