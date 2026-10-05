<?php

namespace App\Modules\Identity\Domain\Ports;

/**
 * Where export archives live: the private disk, one folder per person,
 * written under a `.part` name and renamed when complete (spec 022, R-5).
 */
interface ExportStore
{
    /**
     * The relative path of the finished archive.
     */
    public function pathFor(string $userId, string $exportId): string;

    /**
     * The absolute path to build the archive at, its folder created.
     */
    public function partFile(string $userId, string $exportId): string;

    /**
     * Renames the part file to the finished archive.
     *
     * @return int the archive's size in bytes
     */
    public function commit(string $userId, string $exportId): int;

    /**
     * The absolute path of a finished archive, or null when it is not there.
     */
    public function absolutePath(string $relativePath): ?string;

    /**
     * Deletes a finished archive and any part file of the same export.
     */
    public function delete(string $userId, string $exportId): void;

    /**
     * Deletes the whole folder of a person.
     */
    public function deleteAllFor(string $userId): void;

    /**
     * Every archive and part file on disk.
     *
     * @return list<string> relative paths
     */
    public function allFiles(): array;

    public function deleteFile(string $relativePath): void;
}
