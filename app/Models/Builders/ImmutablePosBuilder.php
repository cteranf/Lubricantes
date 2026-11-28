<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/** Prevent query-level mutations that bypass POS model events. */
class ImmutablePosBuilder extends Builder
{
    public function update(array $values)
    {
        throw new LogicException('Los registros POS no admiten actualizaciones Eloquent masivas.');
    }

    public function delete()
    {
        throw new LogicException('Los registros POS no admiten borrados Eloquent masivos.');
    }

    public function forceDelete()
    {
        throw new LogicException('Los registros POS no admiten borrados Eloquent forzados.');
    }

    public function truncate()
    {
        throw new LogicException('Los registros POS no admiten truncado mediante Eloquent.');
    }
}
