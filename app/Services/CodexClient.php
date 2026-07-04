<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs the OpenAI Codex CLI (`codex exec`) in non-interactive mode and
 * returns the final assistant message.
 *
 * Auth comes from the ChatGPT Pro OAuth session in $CODEX_HOME —
 * docker-compose mounts the host's ~/.codex there. The refresh token
 * rotates inside that directory, so it must stay read-write.
 */
class CodexClient
{
    /**
     * @param  array<string, mixed>|null  $outputSchema  JSON Schema the model must satisfy
     */
    public function exec(string $prompt, ?array $outputSchema = null, ?int $timeout = null): string
    {
        $bin = (string) config('services.codex.bin');
        $model = trim((string) config('services.codex.model'));
        $timeout ??= (int) config('services.codex.timeout');

        $lastMessagePath = tempnam(sys_get_temp_dir(), 'codex-out-');
        $schemaPath = null;

        try {
            $cmd = [
                $bin, 'exec',
                '--sandbox', 'read-only',
                '--skip-git-repo-check',
                '--ephemeral',
                '--color', 'never',
                '--output-last-message', $lastMessagePath,
            ];

            if ($model !== '') {
                array_push($cmd, '--model', $model);
            }

            if ($outputSchema !== null) {
                $schemaPath = tempnam(sys_get_temp_dir(), 'codex-schema-');
                file_put_contents($schemaPath, json_encode($outputSchema));
                array_push($cmd, '--output-schema', $schemaPath);
            }

            $cmd[] = '-'; // read prompt from stdin

            Log::info('codex exec', [
                'model' => $model !== '' ? $model : '<config default>',
                'schema' => $outputSchema !== null,
                'prompt_chars' => strlen($prompt),
            ]);

            $process = new Process($cmd, base_path(), null, $prompt, $timeout);
            $process->run();

            if (! $process->isSuccessful()) {
                Log::error('codex exec failed', [
                    'exit_code' => $process->getExitCode(),
                    'stderr' => substr($process->getErrorOutput(), 0, 500),
                ]);
                throw new RuntimeException('codex failed: '.substr($process->getErrorOutput(), 0, 300));
            }

            $message = (string) file_get_contents($lastMessagePath);

            // Codex occasionally exits 0 without writing the file.
            return $message !== '' ? $message : $process->getOutput();
        } finally {
            @unlink($lastMessagePath);
            if ($schemaPath !== null) {
                @unlink($schemaPath);
            }
        }
    }

    /**
     * Find the first JSON object in model output and decode it. Tolerates
     * fenced code blocks and prose around the object.
     *
     * @return array<string, mixed>
     */
    public function extractJson(string $text): array
    {
        $cleaned = trim($text);

        $decoded = json_decode($cleaned, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $cleaned, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $start = strpos($cleaned, '{');
        $end = strrpos($cleaned, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($cleaned, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new RuntimeException('no JSON object found in Codex output');
    }
}
