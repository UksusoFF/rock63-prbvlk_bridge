<?php

namespace App\Jobs;

use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\Facades\Log;

class PostMessageJob extends Job
{
    protected $message;

    public function __construct($message)
    {
        $this->message = $message;
    }

    public function handle()
    {
        try {
            $client = new HttpClient();
            $message = json_encode(array_merge([
                'method' => 'sendUserMessage',
            ], $this->message));
            Log::debug('Sending message: ' . $message);
            if (!env('APP_DEBUG')) {
                $client->post(env('PRBVLK_API_BASE_URL'), [
                    'form_params' => [
                        'clientId' => env('PRBVLK_CLIENT_ID'),
                        'authKey' => sha1($message . env('PRBVLK_CLIENT_SECRET')),
                        'os' => 'web',
                        'message' => $message,
                    ],
                    'timeout' => env('NETWORK_TIMEOUT'),
                    'connect_timeout' => env('NETWORK_TIMEOUT'),
                ]);
            }
        } catch (\Exception $e) {
            Log::debug('Failed with message: ' . $e->getMessage() . ' and code: ' . $e->getCode());
        }
    }

    public function failed(\Exception $e)
    {
        Log::debug('Failed with message: ' . $e->getMessage() . ' and code: ' . $e->getCode());
    }
}