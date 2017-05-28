<?php

namespace App\Console\Commands;

use App\Jobs\PostMessageJob;
use Carbon\Carbon;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class EventInfoUpdateCommand extends Command
{
    protected $signature = 'event-info:update';
    protected $description = 'Send daily events to prbvlk api.';

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @param string $endPoint
     * @return Collection
     */
    private function getData($endPoint)
    {
        $client = new HttpClient();
        $response = $client->get(implode('/', [env('ROCK63_API_BASE_URL'), $endPoint]), [
            'timeout' => env('NETWORK_TIMEOUT'),
            'connect_timeout' => env('NETWORK_TIMEOUT'),
        ]);
        return collect(json_decode((string)$response->getBody(), true));
    }

    /**
     * @param Collection $events
     * @param Collection $venues
     * @return Collection
     */
    private function filterAndFormatData(Collection $events, Collection $venues)
    {
        return $events->where('notify', '1')->filter(function ($value) {
            $date = Carbon::createFromTimestamp($value['date']['s']);
            return $date->isToday() || $date->isTomorrow();
        })->map(function ($event) use ($venues) {
            $date = Carbon::createFromTimestamp($event['date']['s']);
            $venue = $venues->where('id', $event['v_id'])->first();
            return [
                'TEXT' => implode(' ', [
                    $date->isToday() ? 'Сегодня' : 'Завтра',
                    'концерт',
                    $event['title'],
                    '@',
                    $venue['title'],
                ]),
                'LINK' => str_replace('from=android', 'from=prbvlk', $event['url']),
                'LINKING' => [
                    (object)[
                        'LATITUDE' => $venue['latitude'],
                        'LONGITUDE' => $venue['longitude'],
                        'RADIUS' => env('MESSAGE_RADIUS', 200),
                    ],
                ],
                'EXPIRETIME' => env('MESSAGE_EXPIRETIME', 20),
                'DEVICEID' => env('DEVICEID', ''),
            ];
        });
    }

    public function handle()
    {
        $events = $this->filterAndFormatData($this->getData('events'), $this->getData('venues'));
        if (!$events->isEmpty()) {
            $events->each(function ($event, $key) {
                $job = (new PostMessageJob($event))
                    ->delay(Carbon::now()->addSeconds($key * (int)env('PRBVLK_QUEUE_TIMEOUT')));
                dispatch($job);
            });
        } else {
            Log::debug('No notification events today.');
        }
    }
}