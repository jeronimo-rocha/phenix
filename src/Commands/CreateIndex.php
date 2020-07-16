<?php

namespace Phenix\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Foundation\Application as LaravelApplication;
use Laravel\Lumen\Application as LumenApplication;
use Phenix\Core\Facades\ElasticSearch;
use Exception;

/**
 * Class CreateIndex
 * @package Phenix\Core\Commands
 */
class CreateIndex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elasticsearch:create-index {--reset : If true, will delete the existing index first.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create new or reset elasticsearch index.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->laravel instanceof LaravelApplication) {
            $class = ElasticSearch::class;
        } elseif ($this->laravel instanceof LumenApplication) {
            $class = app('elasticsearch');
        }

        if (empty($class)) {
            $this->error('Application not supported.');
        }

        try {
            $action = 'created';

            if ($this->option('reset')) {
                call_user_func([$class, 'deleteIndex']);

                $action = 'reset';
            }

            call_user_func([$class, 'createIndex']);

            $this->info('Elasticsearch index has been successfully ' . $action . '.');
        } catch (Exception $e) {
            $this->error($e->getMessage());
        }
    }
}
