<?php

declare(strict_types=1);

namespace App\Services;

interface GoogleDriveServiceInterface
{
    /**
     * Get file metadata from Google Drive
     *
     * @param string $fileId The Google Drive file ID
     * @return array File metadata or empty array on error
     */
    public function getFileMetadata(string $fileId): array;

    /**
     * Download file from Google Drive to a specific destination
     *
     * @param string $fileId The Google Drive file ID
     * @param string $destPath Destination path for the downloaded file
     * @return string The MIME type of the downloaded file
     * @throws \Exception If download fails
     */
    public function downloadFile(string $fileId, string $destPath): string;

    /**
     * Download file from Google Drive to a temporary location
     *
     * @param string $fileId The Google Drive file ID
     * @param string $prefix Prefix for the temporary file name
     * @return array Array with 'path' and 'size' keys
     * @throws \Exception If download fails
     */
    public function downloadFileToTemp(string $fileId, string $prefix = 'gdrive_'): array;
}
