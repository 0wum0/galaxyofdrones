<?php

namespace App\Support;

/**
 * File-based installer state tracker.
 *
 * Stores installer progress in storage/app/installer_state.json so that
 * the installation flow survives APP_KEY changes, session invalidation,
 * and other disruptions common on shared hosting environments.
 *
 * This replaces the fragile session()->put('install_*') approach that
 * broke whenever the .env was rewritten mid-install (new APP_KEY
 * invalidates the encrypted session cookie → redirect loop).
 */
class InstallerState
{
    protected string $path;

    public function __construct()
    {
        $this->path = storage_path('app/installer_state.json');
    }

    /**
     * Get the full state array.
     */
    public function all(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }

        $content = @file_get_contents($this->path);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Get a single value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $data = $this->all();
        return $data[$key] ?? $default;
    }

    /**
     * Set one or more values (merge into existing state).
     */
    public function set(array $values): void
    {
        $data = $this->all();
        $data = array_merge($data, $values);
        $data['updated_at'] = date('Y-m-d H:i:s');

        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($this->path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($this->path, 0600);
    }

    /**
     * Check if a step has been completed.
     */
    public function hasCompleted(string $step): bool
    {
        return (bool) $this->get($step, false);
    }

    /**
     * Mark a step as completed.
     */
    public function markCompleted(string $step): void
    {
        $this->set([$step => true, $step . '_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Reset all state (for fresh install).
     */
    public function reset(): void
    {
        if (file_exists($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * Remove the state file (post-install cleanup).
     */
    public function cleanup(): void
    {
        $this->reset();
    }
}
