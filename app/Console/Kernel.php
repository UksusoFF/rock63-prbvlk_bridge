<?php

namespace App\Console;

use App\Console\Commands\EventInfoUpdateCommand;
use Laravel\Lumen\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        EventInfoUpdateCommand::class,
    ];
}
