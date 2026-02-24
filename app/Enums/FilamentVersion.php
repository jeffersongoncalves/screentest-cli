<?php

namespace App\Enums;

enum FilamentVersion: string
{
    case V3 = 'v3';
    case V4 = 'v4';
    case V5 = 'v5';

    public static function fromComposerConstraint(string $constraint): self
    {
        return match (true) {
            str_starts_with($constraint, '^3') || str_starts_with($constraint, '3.') => self::V3,
            str_starts_with($constraint, '^4') || str_starts_with($constraint, '4.') => self::V4,
            str_starts_with($constraint, '^5') || str_starts_with($constraint, '5.') => self::V5,
            default => throw new \ValueError("Unable to determine Filament version from constraint: {$constraint}"),
        };
    }
}
