<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
| Driven by `php artisan schedule:run` every minute — a Windows Task Scheduler
| entry locally, cron in production. See SETUP.md.
*/

// withoutOverlapping is belt-and-braces: the command already claims each
// campaign with a conditional UPDATE, so a slow run cannot double-dispatch.
Schedule::command('campaigns:dispatch-scheduled')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Each mailbox carries its own interval, so this runs every minute and only
// decides who is due — the fetch itself goes to the queue, because an IMAP
// round trip can outlast the gap to the next tick.
Schedule::command('mailboxes:sync')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Automations tick every minute and normally queue nothing: the command counts
// what is due first, so an account with no automations costs one indexed count
// rather than a job. The runner claims each run with a conditional UPDATE, so
// withoutOverlapping here is convenience, not the thing preventing a
// double-send.
Schedule::command('automations:tick')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// A split test sits still between its sample going out and its window closing,
// so nothing in the send path can notice the moment it becomes decidable. This
// looks, every minute, and normally answers in one indexed count.
Schedule::command('campaigns:decide-ab')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();
