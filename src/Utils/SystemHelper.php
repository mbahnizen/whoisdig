<?php
namespace WhoisDig\Utils;

class SystemHelper
{
    /**
     * Determine if running on Windows.
     */
    public static function isWindows()
    {
        return DIRECTORY_SEPARATOR === '\\';
    }

    /**
     * Determine if running on Linux/Unix.
     */
    public static function isLinux()
    {
        return DIRECTORY_SEPARATOR === '/';
    }

    /**
     * Normalize a file path for cross-platform compatibility.
     * Converts backslashes to forward slashes and collapses multiple slashes.
     * Safely preserves Windows drive letters (e.g. C:/) and protocol schemas.
     */
    public static function normalizePath($path)
    {
        // Convert backslashes to forward slashes
        $normalized = str_replace('\\', '/', $path);
        
        // Collapse multiple slashes into a single slash, 
        // taking care to not break protocols like http:// or file:///
        $normalized = preg_replace('#(?<!:)//+#', '/', $normalized);
        
        return $normalized;
    }

    /**
     * Safely acquire a file lock across platforms.
     * Handles both Windows fopen locking errors and Linux flock contention.
     * 
     * @param string $file The path to the file.
     * @param string $mode The file open mode (e.g., 'c+', 'a+').
     * @param int $timeoutMs Maximum wait time in milliseconds.
     * @return resource|false Returns the file pointer on success, or false on timeout/failure.
     */
    public static function acquireLock($file, $mode = 'c+', $timeoutMs = 5000)
    {
        $start = microtime(true);
        $file = self::normalizePath($file);

        while (true) {
            // Windows: fopen will fail if another process holds an exclusive lock.
            // Linux: fopen will succeed, but flock will block.
            $fp = @fopen($file, $mode);

            if ($fp) {
                // Attempt to acquire an exclusive lock without blocking indefinitely.
                // LOCK_NB is critical to prevent deadlocks on Linux if a process hangs.
                if (@flock($fp, LOCK_EX | LOCK_NB)) {
                    return $fp;
                }
                
                // Failed to acquire lock, close the handle before retrying.
                @fclose($fp);
            }

            if ((microtime(true) - $start) * 1000 > $timeoutMs) {
                return false;
            }

            usleep(50000); // Sleep for 50ms before retrying
        }
    }
}
