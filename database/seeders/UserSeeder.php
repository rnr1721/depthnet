<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Создаем тестового пользователя, если он не существует
        if (!User::where('email', 'test@example.com')->exists()) {
            User::create([
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => Hash::make('password'),
            ]);
            $this->command->info('Тестовый пользователь успешно создан.');
        } else {
            $this->command->info('Тестовый пользователь уже существует.');
        }

        // Создаем администратора, если он не существует
        if (!User::where('email', 'admin@example.com')->exists()) {
            User::create([
                'name' => 'Admin',
                'email' => 'admin@example.com',
                'password' => Hash::make('admin123'),
                'is_admin' => true,
            ]);
            $this->command->info('Администратор успешно создан.');
        } else {
            $this->command->info('Администратор уже существует.');
        }
    }
}
