<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LogController extends Controller
{
    /**
     * Maximum lines that can be requested.
     */
    const MAX_LINES = 1000;

    /**
     * Maximum bytes to read from the log file (safety limit: 2 MB).
     */
    const MAX_READ_BYTES = 2 * 1024 * 1024;

    /**
     * Show application logs.
     */
    public function index(Request $request)
    {
        $logFile = storage_path('logs/laravel.log');
        $logContent = '';
        $logSize = 0;
        $lines = min((int) $request->get('lines', 100), self::MAX_LINES);
        $lines = max($lines, 1);

        if (file_exists($logFile)) {
            $logSize = filesize($logFile);
            $logContent = $this->tailFile($logFile, $lines);
        }

        $logSizeFormatted = $this->formatBytes($logSize);

        return view('admin.logs.index', compact('logContent', 'logSizeFormatted', 'lines'));
    }

    /**
     * Read last N lines from a file using buffered backward reading.
     *
     * Uses a buffer-based approach instead of char-by-char for performance.
     * Caps total bytes read to MAX_READ_BYTES to prevent memory exhaustion.
     */
    protected function tailFile(string $path, int $lines = 100): string
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return 'Unable to read log file.';
        }

        fseek($handle, 0, SEEK_END);
        $fileSize = ftell($handle);

        if ($fileSize === 0) {
            fclose($handle);

            return 'Log file is empty.';
        }

        // Cap the read size
        $maxRead = min($fileSize, self::MAX_READ_BYTES);

        // Read from the end in a single buffer
        $offset = max(0, $fileSize - $maxRead);
        fseek($handle, $offset);
        $buffer = fread($handle, $maxRead);
        fclose($handle);

        if ($buffer === false) {
            return 'Unable to read log file.';
        }

        // Split into lines and take the last N
        $allLines = explode("\n", $buffer);

        // Remove trailing empty line from explode
        if (end($allLines) === '') {
            array_pop($allLines);
        }

        $tail = array_slice($allLines, -$lines);

        return implode("\n", $tail);
    }

    /**
     * Format bytes to human readable.
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}
