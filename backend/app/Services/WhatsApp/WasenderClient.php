<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around wasender.dev's text and document message endpoints.
 *
 * @see https://wasender.dev
 */
class WasenderClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.wasender.token'));
    }

    /**
     * A 2xx response means WhatsApp accepted the message for delivery.
     *
     * @param  string  $to  recipient in E.164 format, e.g. +919876543210
     * @return array<string, mixed> decoded API response
     *
     * @throws RequestException on a non-2xx response (lets the queue retry)
     */
    public function sendText(string $to, string $text): array
    {
        return Http::withToken(config('services.wasender.token'))
            ->acceptJson()
            ->timeout(config('services.wasender.timeout'))
            ->post(config('services.wasender.url'), [
                // The API wants international format without the leading "+".
                'to' => ltrim($to, '+'),
                'body' => $text,
            ])
            ->throw()
            ->json() ?? [];
    }

    /**
     * Send a file (e.g. the PDF bill) with an optional caption. The file goes
     * inline as a base64 data URI, so it doesn't need a public URL.
     *
     * @param  string  $to  recipient in E.164 format, e.g. +919876543210
     * @return array<string, mixed> decoded API response
     *
     * @throws RequestException on a non-2xx response (lets the queue retry)
     */
    public function sendDocument(string $to, string $contents, string $filename, string $mimeType, ?string $caption = null): array
    {
        return Http::withToken(config('services.wasender.token'))
            ->acceptJson()
            ->timeout(config('services.wasender.timeout'))
            ->post(config('services.wasender.document_url'), array_filter([
                'to' => ltrim($to, '+'),
                'media' => "data:{$mimeType};base64,".base64_encode($contents),
                'mime_type' => $mimeType,
                'filename' => $filename,
                'caption' => $caption,
            ]))
            ->throw()
            ->json() ?? [];
    }
}
