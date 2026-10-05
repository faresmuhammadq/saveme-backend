<?php

namespace App\Modules\Identity\Application\Export;

use App\Modules\Identity\Domain\Export\ExportScope;
use App\Modules\Identity\Domain\Export\TransactionsCsv;
use RuntimeException;
use ZipArchive;

/**
 * Builds the archive of an export (spec 022, R-1, R-4): every JSON file is
 * streamed to a temporary file one record at a time, the spreadsheet too, and
 * the ZIP is assembled once at the end with ZipArchive (PHP's bundled zip
 * extension, DEFLATE). Memory stays flat however large the account.
 */
final class ZipExportWriter implements ExportWriter
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR;

    /** @var array<string, array{handle: resource, path: string, count: int}> */
    private array $json = [];

    /** @var array{handle: resource, path: string, count: int}|null */
    private ?array $csv = null;

    public function declare(string $file): void
    {
        $this->open($file);
    }

    public function append(string $file, array $record): void
    {
        $this->open($file);
        $entry = &$this->json[$file];

        $body = implode("\n", array_map(fn (string $line): string => '    '.$line, explode("\n", json_encode($record, self::JSON_FLAGS))));
        fwrite($entry['handle'], ($entry['count'] === 0 ? "\n" : ",\n").$body);
        $entry['count']++;
    }

    public function transactionRow(array $row): void
    {
        $this->csv ??= $this->openCsv();
        fwrite($this->csv['handle'], TransactionsCsv::line($row));
        $this->csv['count']++;
    }

    /**
     * Writes the finished archive, manifest included, and removes the
     * temporary files. Files are added sorted by name.
     */
    public function finish(string $archivePath, ExportTarget $target): void
    {
        $this->csv ??= $this->openCsv();

        $counts = [];
        foreach ($this->json as $file => $entry) {
            $counts[$file] = $entry['count'];
        }
        $counts['transactions.csv'] = $this->csv['count'];
        ksort($counts);

        $manifest = [
            'format' => 1,
            'generated_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'scope' => $target->scope->value,
            'wallet_id' => $target->scope === ExportScope::Wallet ? $target->walletId : null,
            'files' => $counts,
        ];

        foreach ($this->json as $entry) {
            fwrite($entry['handle'], $entry['count'] === 0 ? "]\n" : "\n]\n");
            fclose($entry['handle']);
        }
        fclose($this->csv['handle']);

        $zip = new ZipArchive;

        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The export archive could not be opened.');
        }

        $zip->addFromString('manifest.json', json_encode($manifest, self::JSON_FLAGS)."\n");
        $zip->setCompressionName('manifest.json', ZipArchive::CM_DEFLATE);

        $files = $this->json;
        ksort($files);

        foreach ($files as $name => $entry) {
            $zip->addFile($entry['path'], $name);
            $zip->setCompressionName($name, ZipArchive::CM_DEFLATE);
        }

        $zip->addFile($this->csv['path'], 'transactions.csv');
        $zip->setCompressionName('transactions.csv', ZipArchive::CM_DEFLATE);

        if (! $zip->close()) {
            $this->cleanUp();

            throw new RuntimeException('The export archive could not be written.');
        }

        $this->cleanUp();
    }

    /**
     * Drops every temporary file, finished or not.
     */
    public function cleanUp(): void
    {
        foreach ([...array_values($this->json), ...($this->csv === null ? [] : [$this->csv])] as $entry) {
            if (is_resource($entry['handle'])) {
                fclose($entry['handle']);
            }

            if (is_file($entry['path'])) {
                unlink($entry['path']);
            }
        }

        $this->json = [];
        $this->csv = null;
    }

    private function open(string $file): void
    {
        if (isset($this->json[$file])) {
            return;
        }

        $path = $this->temporaryFile();
        $handle = $this->handleFor($path);
        fwrite($handle, '[');
        $this->json[$file] = ['handle' => $handle, 'path' => $path, 'count' => 0];
    }

    /**
     * @return array{handle: resource, path: string, count: int}
     */
    private function openCsv(): array
    {
        $path = $this->temporaryFile();
        $handle = $this->handleFor($path);
        fwrite($handle, TransactionsCsv::headerLine());

        return ['handle' => $handle, 'path' => $path, 'count' => 0];
    }

    /**
     * @return resource
     */
    private function handleFor(string $path)
    {
        return fopen($path, 'w') ?: throw new RuntimeException('A temporary export file could not be opened.');
    }

    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'export');

        if ($path === false) {
            throw new RuntimeException('No temporary file for the export.');
        }

        return $path;
    }
}
