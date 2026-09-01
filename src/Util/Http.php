<?php

declare(strict_types=1);

namespace App\Util;

/**
 * HTTP client minimal (tidak ada Guzzle di proyek ini).
 * Dipakai untuk kirim payload ke webhook n8n.
 *
 * Pakai cURL bila available; fallback ke file_get_contents + stream context
 * biar tetap jalan meski ext-curl tidak terpasang (Laragon standar ya ada,
 * tapi fallback menjaga portabilitas).
 */
class Http
{
    /**
     * POST JSON ke $url.
     *
     * @return array{0:int,1:string,2:?string} [kode_http, body, error]
     */
    public static function post(string $url, array $payload, int $timeoutMs = 5000): array
    {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if (function_exists('curl_init')) {
            return self::postCurl((string) $body, $url, $timeoutMs);
        }

        return self::postStream((string) $body, $url, $timeoutMs);
    }

    /** @return array{0:int,1:string,2:?string} */
    private static function postCurl(string $body, string $url, int $timeoutMs): array
    {
        $ch = curl_init($url);

        // CURLOPT_TIMEOUT_MS pakai NOSIGNAL biar aman di Windows (bisa throw
        // warning "noping" bila pakai CURLOPT_TIMEOUT saja + DNS lambat).
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_TIMEOUT_MS        => $timeoutMs,
            CURLOPT_NOSIGNAL          => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($body),
            ],
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch) ?: null;
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error !== null) {
            return [0, '', $error];
        }

        return [$code, (string) $response, null];
    }

    /** @return array{0:int,1:string,2:?string} */
    private static function postStream(string $body, string $url, int $timeoutMs): array
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n"
                                  . "Accept: application/json\r\n"
                                  . 'Content-Length: ' . strlen($body) . "\r\n",
                'content'       => $body,
                'timeout'       => (int) max(1, (int) ceil($timeoutMs / 1000)),
                'ignore_errors' => true,
                'protocol_version' => 1.1,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $err = error_get_last();

            return [0, '', $err !== null ? $err['message'] : 'HTTP request failed'];
        }

        $code = 0;
        // @phpstan-ignore-next-line isset.variable ($http_response_header is a stream superglobal)
        if (isset($http_response_header)) {
            // Baris pertama: "HTTP/1.1 200 OK".
            if (preg_match('#HTTP/\S+\s+(\d{3})#i', $http_response_header[0], $m)) {
                $code = (int) $m[1];
            }
        }

        return [$code, (string) $response, null];
    }
}
