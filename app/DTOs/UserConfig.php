<?php

namespace App\DTOs;

readonly class UserConfig
{
    public function __construct(
        public string $email = 'admin@example.com',
        public string $password = 'password',
        public string $name = 'Admin User',
        public string $model = 'App\\Models\\User',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            email: $data['email'] ?? 'admin@example.com',
            password: $data['password'] ?? 'password',
            name: $data['name'] ?? 'Admin User',
            model: $data['model'] ?? 'App\\Models\\User',
        );
    }
}
