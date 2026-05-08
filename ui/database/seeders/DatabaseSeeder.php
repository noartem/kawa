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
            'name' => 'Админ А.Д.',
            'email' => 'admin@kawa.localhost',
            'password' => Hash::make('12345678'),
        ]);

        if (app()->environment(['local', 'dev'])) {
            $this->call(AdminShowcaseFlowSeeder::class);
        }
    }
}
