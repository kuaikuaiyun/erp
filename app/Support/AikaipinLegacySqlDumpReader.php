<?php

namespace App\Support;

use ZipArchive;

class AikaipinLegacySqlDumpReader
{
    public function resolvePath(string $path): string
    {
        $path = trim($path, " \t\n\r\0\x0B\"'");
        if ($path === '') {
            throw new \RuntimeException(__('aikaipin.import.errors.file_required'));
        }

        $isAbsolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        $candidate = $isAbsolute ? $path : base_path($path);
        $resolved = realpath($candidate);

        if ($resolved === false || !is_file($resolved)) {
            throw new \RuntimeException(__('aikaipin.import.errors.file_not_found', ['path' => $candidate]));
        }

        return $resolved;
    }

    public function readSqlDump(string $path): string
    {
        $size = filesize($path);
        if ($size === false || $size <= 0) {
            throw new \RuntimeException(__('aikaipin.import.errors.file_empty'));
        }
        if ($size > 100 * 1024 * 1024) {
            throw new \RuntimeException(__('aikaipin.import.errors.file_too_large'));
        }
        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'zip') {
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new \RuntimeException(__('aikaipin.import.errors.file_read_failed'));
            }

            return $sql;
        }
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException(__('aikaipin.import.errors.zip_extension_required'));
        }

        $archive = new ZipArchive();
        if ($archive->open($path) !== true) {
            throw new \RuntimeException(__('aikaipin.import.errors.zip_open_failed'));
        }

        try {
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);
                if (!is_array($entry) || !str_ends_with(strtolower((string) ($entry['name'] ?? '')), '.sql')) {
                    continue;
                }
                if ((int) ($entry['size'] ?? 0) > 100 * 1024 * 1024) {
                    throw new \RuntimeException(__('aikaipin.import.errors.file_too_large'));
                }
                $sql = $archive->getFromIndex($index);
                if ($sql === false) {
                    throw new \RuntimeException(__('aikaipin.import.errors.file_read_failed'));
                }

                return $sql;
            }
        } finally {
            $archive->close();
        }

        throw new \RuntimeException(__('aikaipin.import.errors.sql_not_found'));
    }

    /** @return list<array<string,mixed>> */
    public function readTableRows(string $sql, string $table): array
    {
        $defaultColumns = $this->tableColumns($sql, $table);
        $rows = [];
        $quote = chr(96);
        $needle = 'INSERT INTO '.$quote.$table.$quote;
        $offset = 0;

        while (($statementStart = strpos($sql, $needle, $offset)) !== false) {
            $header = substr($sql, $statementStart);
            $pattern = '/^INSERT INTO \x60'.preg_quote($table, '/').'\x60(?: \((?<columns>[^)]*)\))? VALUES\s*/';
            if (preg_match($pattern, $header, $matches) !== 1) {
                throw new \RuntimeException(__('aikaipin.import.errors.sql_invalid', ['table' => $table]));
            }
            $valuesStart = $statementStart + strlen($matches[0]);
            $statementEnd = $this->findStatementEnd($sql, $valuesStart);
            $columns = $this->insertColumns($matches['columns'] ?? '', $defaultColumns);

            foreach ($this->parseValueTuples(substr($sql, $valuesStart, $statementEnd - $valuesStart)) as $values) {
                if (count($columns) !== count($values)) {
                    throw new \RuntimeException(__('aikaipin.import.errors.column_count_invalid', ['table' => $table]));
                }

                /** @var array<string,mixed> $row */
                $row = array_combine($columns, $values);
                $rows[] = $row;
            }
            $offset = $statementEnd + 1;
        }

        return $rows;
    }

    /** @return list<string> */
    private function tableColumns(string $sql, string $table): array
    {
        if (preg_match('/CREATE TABLE \x60'.preg_quote($table, '/').'\x60 \((?<body>.*?)\) ENGINE/s', $sql, $matches) !== 1) {
            throw new \RuntimeException(__('aikaipin.import.errors.table_not_found', ['table' => $table]));
        }
        preg_match_all('/^\s*\x60([^\x60]+)\x60/m', $matches['body'], $columns);

        return $columns[1] ?? [];
    }

    private function findStatementEnd(string $sql, int $offset): int
    {
        $quoted = false;
        $length = strlen($sql);

        for ($index = $offset; $index < $length; $index++) {
            $character = $sql[$index];
            if ($quoted) {
                if ($character === '\\') {
                    $index++;

                    continue;
                }
                if ($character === "'") {
                    $quoted = false;
                }

                continue;
            }
            if ($character === "'") {
                $quoted = true;
            } elseif ($character === ';') {
                return $index;
            }
        }

        throw new \RuntimeException(__('aikaipin.import.errors.sql_invalid', ['table' => 'unknown']));
    }

    /** @param list<string> $defaultColumns
     *  @return list<string>
     */
    private function insertColumns(string $insertColumns, array $defaultColumns): array
    {
        if (trim($insertColumns) === '') {
            return $defaultColumns;
        }
        preg_match_all('/\x60([^\x60]+)\x60/', $insertColumns, $columns);

        return $columns[1] ?? [];
    }

    /** @return list<list<mixed>> */
    private function parseValueTuples(string $values): array
    {
        $rows = [];
        $length = strlen($values);
        $offset = 0;

        while ($offset < $length) {
            while ($offset < $length && (ctype_space($values[$offset]) || $values[$offset] === ',')) {
                $offset++;
            }
            if ($offset >= $length) {
                break;
            }
            if ($values[$offset] !== '(') {
                throw new \RuntimeException(__('aikaipin.import.errors.sql_invalid', ['table' => 'unknown']));
            }
            $offset++;
            $row = [];

            while ($offset < $length) {
                while ($offset < $length && ctype_space($values[$offset])) {
                    $offset++;
                }
                if ($offset >= $length) {
                    throw new \RuntimeException(__('aikaipin.import.errors.sql_invalid', ['table' => 'unknown']));
                }

                if ($values[$offset] === "'") {
                    $row[] = $this->readQuotedValue($values, $offset);
                } else {
                    $valueStart = $offset;
                    while ($offset < $length && !in_array($values[$offset], [',', ')'], true)) {
                        $offset++;
                    }
                    $rawValue = trim(substr($values, $valueStart, $offset - $valueStart));
                    $row[] = strtoupper($rawValue) === 'NULL' ? null : $rawValue;
                }

                while ($offset < $length && ctype_space($values[$offset])) {
                    $offset++;
                }
                if ($offset >= $length) {
                    throw new \RuntimeException(__('aikaipin.import.errors.sql_invalid', ['table' => 'unknown']));
                }
                if ($values[$offset] === ',') {
                    $offset++;

                    continue;
                }
                if ($values[$offset] === ')') {
                    $offset++;
                    $rows[] = $row;

                    break;
                }
                throw new \RuntimeException(__('aikaipin.import.errors.sql_invalid', ['table' => 'unknown']));
            }
        }

        return $rows;
    }

    private function readQuotedValue(string $values, int &$offset): string
    {
        $offset++;
        $raw = '';
        $length = strlen($values);

        while ($offset < $length) {
            $character = $values[$offset];
            if ($character === '\\' && $offset + 1 < $length) {
                $raw .= $character.$values[$offset + 1];
                $offset += 2;

                continue;
            }
            if ($character === "'") {
                $offset++;

                return strtr($raw, [
                    '\\0' => "\0",
                    '\\b' => "\b",
                    '\\n' => "\n",
                    '\\r' => "\r",
                    '\\t' => "\t",
                    '\\Z' => "\x1a",
                    '\\\\' => '\\',
                    "\\'" => "'",
                    '\\"' => '"',
                ]);
            }
            $raw .= $character;
            $offset++;
        }

        throw new \RuntimeException(__('aikaipin.import.errors.sql_invalid', ['table' => 'unknown']));
    }
}
