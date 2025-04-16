<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Utils;

// --- Configuration ---
$apiKeyFile = 'gemini-api-key.txt';
$model = 'gemini-1.5-flash-latest'; // @see https://generativelanguage.googleapis.com/v1beta/models/?key=(yourapikey)
$userMessage = "Write an essay of 1000 words or more on a random topic. It must be long enough to showcase SSE chunk streaming";

// --- API Key & URL ---
if (!file_exists($apiKeyFile)) { die("Error: API key file not found.\n"); }
$apiKey = trim(file_get_contents($apiKeyFile));
if (empty($apiKey)) { die("Error: API key file is empty.\n"); }

// Construct URL with alt=sse to force Server-Sent Events format
$apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:streamGenerateContent?key=" . urlencode($apiKey) . "&alt=sse";

// --- Request Data ---
$payload = [ 'contents' => [ [ 'parts' => [ ['text' => $userMessage] ] ] ], ];

// --- Guzzle Client ---
$client = new Client(['timeout' => 300.0, 'connect_timeout' => 15.0]);

echo "Sending message to Gemini ({$model}) using SSE:\n";
echo "-------------------------------------------------\n";

try {
    $response = $client->request('POST', $apiUrl, [
        'json' => $payload,
        'stream' => true,      // Enable Guzzle stream processing
        'http_errors' => true, // Throw exceptions on 4xx/5xx
    ]);

    $stream = $response->getBody();

    // Process the SSE stream line by line
    while (!$stream->eof()) {
        $line = Utils::readLine($stream);

        if (strpos(trim($line), 'data: ') === 0) {
            $jsonStr = trim(substr($line, 5));

            if (empty($jsonStr)) continue; // Skip empty data lines

            $decoded = json_decode($jsonStr, true);

            // Extract and echo text immediately if found
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
                echo $decoded['candidates'][0]['content']['parts'][0]['text'];
                fflush(STDOUT);
            }
            // Minimal error check: Log JSON errors if they occur
            elseif (json_last_error() !== JSON_ERROR_NONE) {
                fwrite(STDERR, "\n[WARN] JSON Decode Error: " . json_last_error_msg() . "\n");
            }
        }
    }

    echo "\n-------------------------------------------------\nStream finished.\n";

} catch (RequestException $e) {
    // Basic error reporting for HTTP/connection issues
    fwrite(STDERR, "\n-------------------------------------------------\n");
    fwrite(STDERR, "Request Error: " . $e->getMessage() . "\n");
    if ($e->hasResponse()) {
        fwrite(STDERR, "HTTP Status: " . $e->getResponse()->getStatusCode() . "\n");
    }
    exit(1);
} catch (\Exception $e) {
    fwrite(STDERR, "\n-------------------------------------------------\n");
    fwrite(STDERR, "General Error: " . $e->getMessage() . "\n");
    exit(1);
}

exit(0); // Success
