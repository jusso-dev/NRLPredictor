<?php

namespace App\Support;

/**
 * Parses the tail of Laravel's storage/logs/laravel.log into structured entries
 * for the /logs viewer. Only reads the last N kilobytes to stay cheap under polling.
 */
class LaravelLogReader
{
    public function __construct(
        protected string $path,
        protected int $maxBytes = 262144, // 256 KB tail window
    ) {}

    /**
     * Read + parse the tail of the log file.
     *
     * @return array<int, array{timestamp:string, env:string, level:string, message:string, context:string}>
     */
    public function entries(): array
    {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            return [];
        }

        $size = filesize($this->path) ?: 0;
        $offset = max(0, $size - $this->maxBytes);

        $fh = @fopen($this->path, 'r');
        if (! $fh) {
            return [];
        }
        fseek($fh, $offset);
        $content = stream_get_contents($fh) ?: '';
        fclose($fh);

        // If we started mid-entry, throw away the leading partial line.
        if ($offset > 0) {
            $content = substr($content, strpos($content, "\n[") ?: 0);
        }

        // Split on newlines preceding "[YYYY-MM-DD HH:MM:SS]" — start of a new entry.
        $chunks = preg_split('/\n(?=\[\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2})/', ltrim($content, "\n")) ?: [];

        $entries = [];
        foreach ($chunks as $chunk) {
            if (! preg_match(
                '/^\[(?P<ts>[^\]]+)\]\s+(?P<env>[^.]+)\.(?P<level>[A-Z]+):\s+(?P<body>.*)$/s',
                $chunk,
                $m,
            )) {
                continue;
            }
            $body = trim($m['body']);
            $context = '';

            // Common Laravel log tail pattern: "message {json context}". Split on that.
            if (preg_match('/^(?P<msg>.*?)(?P<json>\s\{.*\}\s*)$/s', $body, $bm)) {
                $body = trim($bm['msg']);
                $context = trim($bm['json']);
            }

            $entries[] = [
                'timestamp' => $m['ts'],
                'env' => $m['env'],
                'level' => $m['level'],
                'message' => $body,
                'context' => $context,
            ];
        }

        return $entries;
    }
}
