<?php
namespace WhoisDig\Clients;

use WhoisDig\Utils\CircuitBreaker;
use WhoisDig\Utils\Metrics;

class WhoisClient
{
    private $circuitBreaker;

    public function __construct(CircuitBreaker $cb)
    {
        $this->circuitBreaker = $cb;
    }

    public function query($domain, $server, $fallbackServer = null)
    {
        // Check circuit
        if (!$this->circuitBreaker->isAvailable($server)) {
            Metrics::record('whois_blacklisted_server_skipped', $server);
            if ($fallbackServer && $this->circuitBreaker->isAvailable($fallbackServer)) {
                return $this->execute($domain, $fallbackServer, 5);
            }
            throw new \Exception("WHOIS server $server is temporarily blacklisted by Circuit Breaker.");
        }

        // Attempt 1
        try {
            return $this->execute($domain, $server, 3); // 3 sec initial timeout
        } catch (\Exception $e) {
            Metrics::record('whois_failure_attempt_1', $server);
            $this->circuitBreaker->recordFailure($server);
        }

        // Attempt 2 (Same server, higher adaptive timeout)
        usleep(500000); // 500ms delay
        try {
            return $this->execute($domain, $server, 7); // 7 sec timeout
        } catch (\Exception $e) {
            Metrics::record('whois_failure_attempt_2', $server);
            $this->circuitBreaker->recordFailure($server);
        }

        // Attempt 3 (Fallback)
        if ($fallbackServer && $this->circuitBreaker->isAvailable($fallbackServer)) {
            try {
                return $this->execute($domain, $fallbackServer, 7);
            } catch (\Exception $e) {
                Metrics::record('whois_failure_fallback', $fallbackServer);
                $this->circuitBreaker->recordFailure($fallbackServer);
            }
        }

        throw new \Exception("WHOIS discovery failed across all retry avenues.");
    }

    private function execute($domain, $server, $timeout)
    {
        $start = microtime(true);
        $fp = @fsockopen($server, 43, $errno, $errstr, $timeout);
        
        if (!$fp) {
            throw new \Exception("Connection refused or timeout to $server: $errstr");
        }

        stream_set_timeout($fp, $timeout);
        fwrite($fp, $domain . "\r\n");

        $response = '';
        while (!feof($fp)) {
            $chunk = fgets($fp, 128);
            if ($chunk !== false) {
                $response .= $chunk;
            }
            $info = stream_get_meta_data($fp);
            if ($info['timed_out']) {
                fclose($fp);
                throw new \Exception("Stream read timeout on $server");
            }
        }
        fclose($fp);
        
        $duration = round((microtime(true) - $start) * 1000);
        Metrics::record('whois_success', $server, $duration);
        $this->circuitBreaker->recordSuccess($server);

        return $response;
    }
}
