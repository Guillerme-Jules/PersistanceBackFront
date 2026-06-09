<?php

namespace App\Enum;

enum Metric: string
{
    case Speed = 'speed';
    case Density = 'density';
    case Bt = 'bt';
    case Bz = 'bz';

    public function column(): string
    {
        return $this->value;
    }
}
