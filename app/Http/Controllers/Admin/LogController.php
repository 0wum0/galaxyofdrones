<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LogController extends Controller
{
    /**
     * Show application logs.
     */
    public function index(Request $request)
    {
        $logFile = storage_path('logs/laravel.log');
        $logContent = '';
        $logSize = 0;
        $lines = (int) $request->get('lines', 100);

        if (file_exists($logFile)) {
            $logSize = filesize($logFile);

            // Read last N lines efficiently
            $logContent = $this->tailFile($logFile, $lines);
        }

        $logSizeFormatted = $this->formatBytes($logSize);

        return view('admin.logs.index', compact('logContent', 'logSizeFormatted', 'lines'));
    }

    /**
     * Read last N lines from a file.
     */
    protected function tailFile(string $path, int $lines = 100): string
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return 'Unable to read log file.';
        }

        // Seek to end and read backwards
        $buffer = '';
        $lineCount = 0;
        $pos = -1;

        fseek($handle, 0, SEEK_END);
        $fileSize = ftell($handle);

        if ($fileSize === 0) {
            fclose($handle);
            return 'Log file is empty.';
        }

        while ($lineCount < $lines && abs($pos) <= $fileSize) {
            fseek($handle, $pos, SEEK_END);
            $char = fgetc($handle);
            $buffer = $char . $buffer;

            if ($char === "\n") {
                $lineCount++;
            }

            $pos--;
        }

        fclose($handle);

        return trim($buffer);
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

        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}
