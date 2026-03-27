<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->admin()->withoutTwoFactor()->create([
            'name' => 'Admin',
            'email' => 'admin@kawaflow.localhost',
            'password' => Hash::make('12345678'),
        ]);

        User::factory()->withoutTwoFactor()->create([
            'name' => 'Artem Noskov',
            'email' => 'artem@noartem.ru',
            'password' => Hash::make('12345678'),
        ]);

        if (app()->environment(['local', 'dev'])) {
            $this->call(AdminShowcaseFlowSeeder::class);
        }
    }
}
