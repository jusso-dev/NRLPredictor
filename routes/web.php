<?php

use App\Livewire\Accuracy;
use App\Livewire\Backtest;
use App\Livewire\Chat;
use App\Livewire\Dashboard;
use App\Livewire\Jobs;
use App\Livewire\Leaderboard;
use App\Livewire\Logs;
use App\Livewire\MatchDetail;
use App\Livewire\Calibration;
use App\Livewire\Learning;
use App\Livewire\Methodology;
use App\Livewire\MultiBet;
use App\Livewire\ValuePicks;
use Illuminate\Support\Facades\Route;

Route::get('/', Dashboard::class)->name('dashboard');
Route::get('/match/{match}', MatchDetail::class)->name('match.detail');
Route::get('/leaderboard', Leaderboard::class)->name('leaderboard');
Route::get('/accuracy', Accuracy::class)->name('accuracy');
Route::get('/chat', Chat::class)->name('chat');
Route::get('/multi-builder', MultiBet::class)->name('multi-bet');
Route::get('/value-picks', ValuePicks::class)->name('value-picks');
Route::get('/how-it-works', Methodology::class)->name('methodology');
Route::get('/calibration', Calibration::class)->name('calibration');
Route::get('/learning', Learning::class)->name('learning');
Route::get('/backtest', Backtest::class)->name('backtest');
Route::get('/jobs', Jobs::class)->name('jobs');
Route::get('/logs', Logs::class)->name('logs');
