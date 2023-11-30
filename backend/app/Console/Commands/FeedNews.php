<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FeedNews extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'feednews';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'News feed completed successfully';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        app()->call('App\Http\Controllers\Api\FetchNewsController@fetchNews');
        $this->info('News Feed executed successfully.');
    }
}
