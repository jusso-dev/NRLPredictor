<?php

namespace App\Livewire;

use App\Services\MultiBetBuilder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

class MultiBet extends Component
{
    public string $risk = 'balanced';
    public int $legs = 6;

    public function setRisk(string $risk): void
    {
        if (in_array($risk, ['safe', 'balanced', 'value'], true)) {
            $this->risk = $risk;
        }
    }

    #[Title('Multi Builder — NRL Try Predictor')]
    #[Layout('layouts.app')]
    public function render(MultiBetBuilder $builder)
    {
        $multi = $builder->build($this->legs, $this->risk);

        return view('livewire.multi-bet', [
            'multi' => $multi,
        ]);
    }
}
