<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\Collection;

class EventInfoUpdate extends Command
{
    protected $signature = 'event-info:update';
    protected $description = 'Send daily events to prbvlk api.';

    const ROCK63_API_BASE_URL = 'http://rock63.ru/api';
    const PRBVLK_API_BASE_URL = 'http://tosamara.ru/api/json';
    const NETWORK_TIMEOUT = 15;

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
        $response = $client->get(implode('/', [self::ROCK63_API_BASE_URL, $endPoint]), [
            'timeout' => self::NETWORK_TIMEOUT,
            'connect_timeout' => self::NETWORK_TIMEOUT,
        ]);
        return collect(json_decode((string)$response->getBody(), true));
    }

    /**
     * @param array $message
     */
    private function sendMessage(array $message)
    {
        try {
            $client = new HttpClient();
            $message = json_encode(array_merge([
                'method' => 'sendUserMessage',
            ], $message));
            $client->post(self::PRBVLK_API_BASE_URL, [
                'form_params' => [
                    'clientId' => env('PRBVLK_CLIENT_ID', ''),
                    'authKey' => sha1($message . env('PRBVLK_CLIENT_SECRET', '')),
                    'os' => 'web',
                    'message' => $message,
                ],
                'timeout' => self::NETWORK_TIMEOUT,
                'connect_timeout' => self::NETWORK_TIMEOUT,
            ]);
        } catch (\Exception $e) {
            //TODO: Add error handler.
        }
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
            $events->each(function ($event) {
                $this->sendMessage($event);
            });
        }
    }
}