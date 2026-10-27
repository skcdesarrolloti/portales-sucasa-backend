<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ciencuadras:verify-pending --limit=25')
    ->everyMinute()
    ->withoutOverlapping(60);

Schedule::command('fincaraiz:auto-sync')
    ->everyFiveMinutes()
    ->name('fincaraiz-auto-sync-v2')
    ->withoutOverlapping(10);

Schedule::command('ciencuadras:reconcile-active --grace=30')
    ->everyTenMinutes()
    ->when(fn () => (bool) config('portals.ciencuadras.auto_sync'))
    ->withoutOverlapping();

Schedule::command('ciencuadras:auto-sync')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('proppit:auto-sync')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('mercadolibre:sync-catalog')
    ->dailyAt('03:30')
    ->withoutOverlapping();

Schedule::command('proppit:refresh-boosted')
    ->weeklyOn(1, '04:00')
    ->withoutOverlapping();
