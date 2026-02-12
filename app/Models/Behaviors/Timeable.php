<?php

namespace App\Models\Behaviors;

use Carbon\Carbon;

trait Timeable
{
    /**
     * Is expired?
     *
     * @return bool
     */
    public function isExpired()
    {
        return ! $this->remaining;
    }

    /**
     * Get the remaining attribute.
     *
     * @return int
     */
    public function getRemainingAttribute()
    {
        $endedAt = $this->getAttribute($this->endedAtKey());

        if ($endedAt === null) {
            return 0;
        }

        if (is_string($endedAt)) {
            try {
                $endedAt = Carbon::parse($endedAt);
            } catch (\Throwable $e) {
                return 0;
            }
        }

        return max(0, Carbon::now()->diffInSeconds($endedAt, false));
    }

    /**
     * Delete all expired.
     *
     * @return bool|null
     */
    public function deleteAllExpired()
    {
        return static::where($this->endedAtKey(), '<', Carbon::now())->delete();
    }

    /**
     * Get the ended at key.
     *
     * @return string
     */
    protected function endedAtKey()
    {
        return 'ended_at';
    }
}
