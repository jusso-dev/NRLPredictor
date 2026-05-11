<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

class Chat extends Component
{
    public string $message = '';
    public array $messages = [];
    public bool $loading = false;

    public function addUserMessage(): void
    {
        $text = trim($this->message);
        if ($text === '' || $this->loading) {
            return;
        }

        $this->messages[] = ['role' => 'user', 'content' => $text];
        $this->message = '';
        $this->loading = true;
    }

    public function receiveReply(string $reply): void
    {
        $this->messages[] = ['role' => 'assistant', 'content' => $reply];
        $this->loading = false;
    }

    public function clearChat(): void
    {
        $this->messages = [];
        $this->loading = false;
    }

    public function getChatHistoryProperty(): array
    {
        // Return messages minus the last user message (which is the current query)
        return array_slice($this->messages, 0, -1);
    }

    #[Title('Chat — NRL Try Predictor')]
    #[Layout('layouts.app')]
    public function render()
    {
        return view('livewire.chat');
    }
}
