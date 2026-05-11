<?php

namespace App\Livewire;

use App\Models\Player;
use App\Models\Prediction;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

class Leaderboard extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'sort')]
    public string $sort = 'current_season_tries';

    #[Url(as: 'dir')]
    public string $direction = 'desc';

    public function sortBy(string $column): void
    {
        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = 'desc';
        }
    }

    #[Title('Leaderboard — NRL Try Predictor')]
    #[Layout('layouts.app')]
    public function render()
    {
        $allowed = ['name', 'current_season_tries', 'current_season_games', 'current_season_try_rate'];
        $column = in_array($this->sort, $allowed, true) ? $this->sort : 'current_season_tries';

        $players = Player::with('team')
            ->when($this->search !== '', fn ($q) => $q->where('name', 'LIKE', "%{$this->search}%"))
            ->orderBy($column, $this->direction === 'asc' ? 'asc' : 'desc')
            ->limit(200)
            ->get();

        $nextScores = Prediction::whereIn('player_id', $players->pluck('id'))
            ->whereHas('match', fn ($q) => $q->where('status', 'upcoming'))
            ->orderByDesc('score')
            ->get()
            ->keyBy('player_id');

        return view('livewire.leaderboard', [
            'players' => $players,
            'nextScores' => $nextScores,
        ]);
    }
}
