<?php

namespace App\Services\Tenants;

enum ShopRole: string
{
    case Cashier = 'cashier';
    case Manager = 'manager';
    case Owner = 'owner';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function rank(?string $role): int
    {
        $position = array_search($role, self::values(), true);

        return $position === false ? -1 : $position;
    }

    public static function atLeast(?string $role, self $minimum): bool
    {
        return self::rank($role) >= self::rank($minimum->value);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
