<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una compra o gasto pagado del efectivo del turno (CAJ-05). */
class GastoCuadre extends ModeloBase
{
    use PerteneceAEmpresa;

    protected $table = 'gasto_cuadre';
    public $timestamps = false;

    protected $fillable = ['hoja_id', 'orden', 'descripcion', 'monto_centavos'];

    protected function casts(): array
    {
        return ['monto_centavos' => 'integer'];
    }

    public function hoja(): BelongsTo
    {
        return $this->belongsTo(HojaCuadre::class, 'hoja_id');
    }
}
