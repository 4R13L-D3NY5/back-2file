<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Schedule::command('app:sync-university-data')->dailyAt('03:00');
\Illuminate\Support\Facades\Schedule::command('sync:grupos-externos')->dailyAt('04:00');
