<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [];

    /**
     * Bootstrap the application for artisan commands.
     *
     * @return void
     */
    public function bootstrap()
    {
        parent::bootstrap();

        if ($this->app->environment() != 'production') {
            if (class_exists(\Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class)) {
                $this->app->register(\Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class);
            }
        }
    }

    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Game tick - processes all pending constructions, upgrades, trainings, movements
        // withoutOverlapping: prevents a second tick from starting if the first hasn't finished
        $schedule->command('game:tick')
            ->everyMinute()
            ->withoutOverlapping(5)  // Lock expires after 5 min as safety fallback
            ->runInBackground();

        // Generate new expeditions and missions every 6 hours
        $schedule->command('expedition:generate')
            ->cron('0 */6 * * *')
            ->withoutOverlapping();

        $schedule->command('mission:generate')
            ->cron('0 */6 * * *')
            ->withoutOverlapping();

        // Update rankings hourly
        $schedule->command('rank:update')
            ->hourly()
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
