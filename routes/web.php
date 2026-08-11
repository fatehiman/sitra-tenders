<?php

use App\Livewire\Register;
use Illuminate\Support\Facades\Route;

// The Filament panel is mounted at the domain root ('/') — see
// AppPanelProvider — so it owns the app's home/dashboard route. Only the
// pre-auth registration page lives outside the panel here.
Route::get('/register', Register::class)->middleware('guest')->name('register');
