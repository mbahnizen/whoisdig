<?php
namespace WhoisDig\Tests;

class ProcessRunner
{
    /**
     * Run multiple PHP scripts in parallel and wait for their completion.
     * Guaranteed to prevent zombie processes by explicitly closing pipes and terminating.
     * 
     * @param string[] $scripts Array of script paths to execute.
     * @param int $timeoutMs Maximum allowed execution time in milliseconds.
     * @return array Array of arrays containing 'stdout' and 'stderr' for each process.
     */
    public static function runParallel(array $scripts, $timeoutMs = 15000)
    {
        $processes = [];
        $pipesList = [];
        $results = [];

        // 1. Spawn all processes
        foreach ($scripts as $i => $script) {
            $descriptorspec = [
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w']  // stderr
            ];

            // Use escapeshellarg for safety across both Windows and Linux
            $cmd = 'php ' . escapeshellarg($script);
            
            $process = proc_open($cmd, $descriptorspec, $pipes);
            
            if (is_resource($process)) {
                // Set pipes to non-blocking to prevent deadlock during read
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);

                $processes[$i] = $process;
                $pipesList[$i] = $pipes;
                $results[$i] = ['stdout' => '', 'stderr' => ''];
            }
        }

        // 2. Poll output until all finished or timeout
        $start = microtime(true);
        $active = true;

        while ($active) {
            $active = false;
            
            foreach ($processes as $i => $process) {
                $status = proc_get_status($process);
                
                // Read available output
                $results[$i]['stdout'] .= stream_get_contents($pipesList[$i][1]);
                $results[$i]['stderr'] .= stream_get_contents($pipesList[$i][2]);

                if ($status['running']) {
                    $active = true;
                }
            }

            if ($active) {
                // Check timeout
                if ((microtime(true) - $start) * 1000 > $timeoutMs) {
                    break;
                }
                usleep(50000); // 50ms polling delay
            }
        }

        // 3. Guaranteed Cleanup (Zombie Prevention)
        foreach ($processes as $i => $process) {
            // Read any final lingering output
            $results[$i]['stdout'] .= stream_get_contents($pipesList[$i][1]);
            $results[$i]['stderr'] .= stream_get_contents($pipesList[$i][2]);

            // ALWAYS close pipes BEFORE proc_close to prevent deadlocks
            fclose($pipesList[$i][1]);
            fclose($pipesList[$i][2]);

            $status = proc_get_status($process);
            if ($status['running']) {
                // Force terminate if timeout reached
                proc_terminate($process);
            }

            proc_close($process);
        }

        return $results;
    }
}
