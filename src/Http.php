<?php
declare(strict_types=1);

namespace Placard;

/** Minimal HTTPS GET using cURL when available, otherwise PHP streams. */
final class Http
{
    /**
     * @param list<string> $headers
     * @return array{0: int, 1: string} HTTP status and body
     * @throws \RuntimeException on transport failure (the message never contains the URL)
     */
    public static function get(string $url, array $headers = [], int $timeout = 8): array
    {
        $headers[] = 'User-Agent: reterminal-placard/1.0';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $body = curl_exec($ch);
            if ($body === false) {
                throw new \RuntimeException('Network error: ' . curl_error($ch));
            }
            return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $body];
        }

        $context = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => implode("\r\n", $headers),
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $context);
        $responseHeaders = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);
        if ($body === false || !isset($responseHeaders[0]) || !preg_match('~^HTTP/\S+\s+(\d{3})~', $responseHeaders[0], $m)) {
            throw new \RuntimeException('Network error: request failed');
        }
        return [(int) $m[1], $body];
    }
}
