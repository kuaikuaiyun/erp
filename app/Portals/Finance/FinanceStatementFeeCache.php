<?php

namespace App\Portals\Finance;

use Illuminate\Support\Collection;

/** Request-local spill storage: large, interleaved bills must not be recalculated after eviction. */
class FinanceStatementFeeCache extends \ArrayObject
{
    private array $bills = [];
    private array $shards = [];

    public function forEntries(string $key, Collection $entries, callable $build): array
    {
        $bill = hash('sha256', $key);
        if (! isset($this->bills[$bill])) {
            $calculated = $build();
            $parts = [];
            foreach ($calculated as $id => $values) {
                $parts[(int) $id % 256][$id] = $values;
            }
            $file = @tmpfile();
            if ($file === false) {
                return array_intersect_key($calculated, array_fill_keys($entries->pluck('id')->all(), true));
            }
            $positions = [];
            foreach ($parts as $part => $values) {
                $json = json_encode($values, JSON_THROW_ON_ERROR);
                $positions[$part] = [ftell($file), strlen($json)];
                if (@fwrite($file, $json) !== strlen($json)) {
                    fclose($file);
                    return array_intersect_key($calculated, array_fill_keys($entries->pluck('id')->all(), true));
                }
            }
            $this->bills[$bill] = ['file' => $file, 'positions' => $positions];
        }
        $result = [];
        foreach ($entries as $entry) {
            $part = (int) $entry->id % 256;
            if (! isset($this->bills[$bill]['positions'][$part])) {
                continue;
            }
            $shard = $bill.'-'.$part;
            if (! isset($this->shards[$shard])) {
                if (count($this->shards) >= 16) {
                    unset($this->shards[array_key_first($this->shards)]);
                }
                [$offset, $length] = $this->bills[$bill]['positions'][$part];
                $file = $this->bills[$bill]['file'];
                fseek($file, $offset);
                $this->shards[$shard] = json_decode(stream_get_contents($file, $length), true, 512, JSON_THROW_ON_ERROR);
            }
            if (isset($this->shards[$shard][$entry->id])) {
                $result[$entry->id] = $this->shards[$shard][$entry->id];
            }
        }
        return $result;
    }

    public function __destruct()
    {
        foreach ($this->bills as $bill) {
            fclose($bill['file']);
        }
    }
}
