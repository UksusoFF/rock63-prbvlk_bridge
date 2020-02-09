<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\RequestOptions;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class EventInfoUpdateCommand extends Command
{
    protected $signature = 'event-info:update';

    protected $description = 'Send daily events to prbvlk api.';

    private function getData(string $endPoint): Collection
    {
        $client = new HttpClient();
        $response = $client->get(implode('/', [env('ROCK63_API_BASE_URL'), $endPoint]), [
            'timeout' => env('NETWORK_TIMEOUT'),
            'connect_timeout' => env('NETWORK_TIMEOUT'),
        ]);

        return collect(json_decode((string)$response->getBody(), true));
    }

    private function filterAndFormatData(Collection $events, Collection $venues): Collection
    {
        return $events->where('notify', '1')->filter(function($value) {
            $date = Carbon::createFromTimestamp($value['date']['s']);

            return $date->isToday() || $date->isTomorrow();
        })->map(function($event) use ($venues) {
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

    private function postEvent(array $event, int $counter)
    {
        try {
            $client = new HttpClient();
            $message = json_encode(array_merge([
                'method' => 'sendUserMessage',
            ], $event));
            Log::debug("Sending message: {$message}");
            if (!env('APP_DEBUG')) {
                $response = $client->post(env('PRBVLK_API_BASE_URL'), [
                    RequestOptions::FORM_PARAMS => [
                        'clientId' => env('PRBVLK_CLIENT_ID'),
                        'authKey' => sha1($message . env('PRBVLK_CLIENT_SECRET')),
                        'os' => 'web',
                        'message' => $message,
                    ],
                    RequestOptions::TIMEOUT => env('NETWORK_TIMEOUT'),
                    RequestOptions::CONNECT_TIMEOUT => env('NETWORK_TIMEOUT'),
                    RequestOptions::DELAY => (int)env('PRBVLK_QUEUE_TIMEOUT') * 1000 * $counter,
                ]);
                Log::debug("Response: {$response->getBody()}");
            }
        } catch (Throwable $e) {
            Log::debug("Failed with message: {$e->getMessage()} and code: {$e->getCode()}");
        }
    }

    public function handle(): void
    {
        $events = $this->filterAndFormatData($this->getData('events'), $this->getData('venues'));

        $counter = 0;

        $events->each(function($event) use (&$counter) {
            $this->postEvent($event, $counter);
            $counter++;
        });

        if ($events->isEmpty()) {
            Log::debug('No notification events today.');
        }
    }
}