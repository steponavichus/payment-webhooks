<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('webhooks:redispatch')->everyMinute()->withoutOverlapping();
