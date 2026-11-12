<?php

namespace App\Portals\Finance;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use XMLReader;
use ZipArchive;

class FinanceConfluxOrderReader
{
    private const MAX_ROWS = 120000;

    public function rows(UploadedFile $file, string $sheetName): \Generator
    {
        $path = $file->getRealPath();
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->invalidFile();
        }
        $calendar = Date::getExcelCalendar();
        $reader = null;
        try {
            $book = $this->metadata($zip, 'xl/workbook.xml');
            $relations = $this->metadata($zip, 'xl/_rels/workbook.xml.rels');
            $sheets = iterator_to_array($book->sheets->sheet, false);
            $sheet = $sheets[(int) ($book->bookViews->workbookView['activeTab'] ?? 0)] ?? $sheets[0] ?? null;
            foreach ($sheets as $candidate) {
                if ((string) $candidate['name'] === $sheetName) {
                    $sheet = $candidate;
                    break;
                }
            }
            if ($sheet === null) {
                $this->invalidFile();
            }
            $relationshipId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $sheetPath = $stringsPath = null;
            foreach ($relations->Relationship as $relation) {
                if ((string) $relation['TargetMode'] === 'External') {
                    continue;
                }
                $target = (string) $relation['Target'];
                $entry = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                if ((string) $relation['Id'] === $relationshipId && str_ends_with((string) $relation['Type'], '/worksheet')) {
                    $sheetPath = $entry;
                }
                if (str_ends_with((string) $relation['Type'], '/sharedStrings')) {
                    $stringsPath = $entry;
                }
            }
            if ($sheetPath === null) {
                $this->invalidFile();
            }
            Date::setExcelCalendar(in_array((string) ($book->workbookPr['date1904'] ?? ''), ['1', 'true'], true)
                ? Date::CALENDAR_MAC_1904 : Date::CALENDAR_WINDOWS_1900);
            $strings = $stringsPath === null ? [] : $this->sharedStrings($zip, $path, $stringsPath);
            $reader = $this->open($zip, $path, $sheetPath, 256 * 1024 ** 2);
            $line = $column = 0;
            $cells = $formulas = [];
            $type = '';
            $complete = false;
            while (@$reader->read()) {
                $name = $reader->localName;
                if ($reader->nodeType === XMLReader::ELEMENT) {
                    if ($name === 'row') {
                        $line = (int) $reader->getAttribute('r');
                        if ($line > self::MAX_ROWS + 1) {
                            throw ValidationException::withMessages(['file' => __('finance.errors.size', ['rows' => number_format(self::MAX_ROWS)])]);
                        }
                        $cells = $formulas = [];
                    } elseif ($name === 'c' && $line > 0) {
                        $address = $reader->getAttribute('r') ?? '';
                        if (! preg_match('/^([A-Z]+)\d+$/D', $address, $matches)) {
                            $this->invalidFile();
                        }
                        $column = Coordinate::columnIndexFromString($matches[1]);
                        if ($column > 100) {
                            throw ValidationException::withMessages(['file' => __('finance.errors.size', ['rows' => number_format(self::MAX_ROWS)])]);
                        }
                        $type = $reader->getAttribute('t') ?? '';
                        if ($reader->isEmptyElement) {
                            $column = 0;
                        }
                    } elseif ($column > 0 && $name === 'f') {
                        $formulas[$column] = true;
                    } elseif ($column > 0 && ($name === 'v' || ($name === 't' && $type === 'inlineStr'))) {
                        $value = @$reader->readString();
                        $cells[$column] = ($cells[$column] ?? '').match ($type) {
                            's' => $strings[(int) $value] ?? '',
                            'b' => $value === '1' ? '1' : '',
                            default => $value,
                        };
                    }
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                    if ($name === 'c') {
                        $column = 0;
                    } elseif ($name === 'row' && $line > 0) {
                        yield $line => [$cells, $formulas];
                        $line = 0;
                    } elseif ($name === 'worksheet') {
                        $complete = true;
                    }
                }
            }
            if (! $complete) {
                $this->invalidFile();
            }
        } finally {
            $reader?->close();
            $zip->close();
            Date::setExcelCalendar($calendar);
        }
    }

    private function metadata(ZipArchive $zip, string $entry): \SimpleXMLElement
    {
        $stat = $zip->statName($entry);
        if ($stat === false || $stat['size'] > 1024 ** 2) {
            $this->invalidFile();
        }
        $xml = @simplexml_load_string($zip->getFromName($entry), options: LIBXML_NONET);
        if ($xml === false) {
            $this->invalidFile();
        }
        return $xml;
    }

    private function sharedStrings(ZipArchive $zip, string $path, string $entry): array
    {
        $reader = $this->open($zip, $path, $entry, 64 * 1024 ** 2);
        $strings = [];
        $value = '';
        $complete = false;
        try {
            while (@$reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') {
                    $value .= @$reader->readString();
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si') {
                    $strings[] = $value;
                    $value = '';
                } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si' && $reader->isEmptyElement) {
                    $strings[] = '';
                } elseif ($reader->localName === 'sst' && ($reader->nodeType === XMLReader::END_ELEMENT || $reader->isEmptyElement)) {
                    $complete = true;
                }
            }
        } finally {
            $reader->close();
        }
        if (! $complete) {
            $this->invalidFile();
        }
        return $strings;
    }

    private function open(ZipArchive $zip, string $path, string $entry, int $maxBytes): XMLReader
    {
        $stat = $zip->statName($entry);
        if ($stat === false || $stat['size'] > $maxBytes || ! str_starts_with($entry, 'xl/') || str_contains($entry, '..')) {
            $this->invalidFile();
        }
        $reader = new XMLReader;
        if (! @$reader->open('zip://'.str_replace('\\', '/', $path).'#'.$entry, null, LIBXML_NONET)) {
            $this->invalidFile();
        }
        return $reader;
    }

    private function invalidFile(): never
    {
        throw ValidationException::withMessages(['file' => __('finance.errors.file')]);
    }
}
