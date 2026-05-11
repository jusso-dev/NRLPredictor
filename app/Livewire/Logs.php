<?php

namespace App\Livewire;

use App\Support\LaravelLogReader;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

class Logs extends Component
{
    public const LEVELS = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    #[Url(as: 'level')]
    public string $level = '';

    #[Url(as: 'q')]
    public string $search = '';

    /** Narrow to a specific job class — linked from the Jobs page. */
    #[Url(as: 'class')]
    public string $classFilter = '';

    public int $limit = 150;

    /** @var array<int, bool> */
    public array $expanded = [];

    public function toggle(int $index): void
    {
        $this->expanded[$index] = ! ($this->expanded[$index] ?? false);
    }

    public function clearFilters(): void
    {
        $this->level = '';
        $this->search = '';
        $this->classFilter = '';
    }

    public function clearLog(): void
    {
        $path = storage_path('logs/laravel.log');
        if (is_writable($path)) {
            file_put_contents($path, '');
        }
    }

    #[Title('Logs — NRL Try Predictor')]
    #[Layout('layouts.app')]
    public function render()
    {
        $reader = new LaravelLogReader(storage_path('logs/laravel.log'));
        $all = array_reverse($reader->entries()); // newest first

        $filtered = array_values(array_filter($all, function (array $e) {
            if ($this->level !== '' && strtoupper($e['level']) !== strtoupper($this->level)) {
                return false;
            }
            if ($this->classFilter !== '') {
                $haystack = $e['message'].' '.$e['context'];
                if (! str_contains($haystack, $this->classFilter)) {
                    return false;
                }
            }
            if ($this->search !== '') {
                $haystack = $e['message'].' '.$e['context'];
                if (stripos($haystack, $this->search) === false) {
                    return false;
                }
            }
            return true;
        }));

        $entries = array_slice($filtered, 0, $this->limit);

        $counts = [];
        foreach (self::LEVELS as $lvl) {
            $counts[$lvl] = 0;
        }
        foreach ($all as $e) {
            $counts[$e['level']] = ($counts[$e['level']] ?? 0) + 1;
        }

        return view('livewire.logs', [
            'entries' => $entries,
            'counts' => $counts,
            'totalShown' => count($entries),
            'totalMatched' => count($filtered),
            'totalAll' => count($all),
            'levels' => self::LEVELS,
        ]);
    }
}
