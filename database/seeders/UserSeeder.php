<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Default users to create when database is empty
     *
     * @var array<int, array{name: string, email: string, password: string, is_admin: bool}>
     */
    private array $defaultUsers = [
        [
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'admin123',
            'is_admin' => true,
        ],
        [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'is_admin' => false,
        ]
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        if (User::count() > 0) {
            $this->command->info('Users already exist in the system. Skipping seeder.');
            return;
        }

        $this->command->info('No users found. Creating default users...');
        $this->command->line('');

        foreach ($this->defaultUsers as $userData) {
            $user = User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'password' => Hash::make($userData['password']),
                'is_admin' => $userData['is_admin'] ?? false,
            ]);

            $role = $userData['is_admin'] ? 'Administrator' : 'User';

            $this->command->info("✓ {$role} created:");
            $this->command->line("  Name: {$userData['name']}");
            $this->command->line("  Email: {$userData['email']}");
            $this->command->line("  Password: {$userData['password']}");
            $this->command->line('');
        }

        $this->command->info('All default users created successfully!');
    }
}
