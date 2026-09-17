<?php

namespace App\Models;

use App\Domain\Cuadre;
use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El cierre de caja de un turno (CAJ-11).
 *
 * Guarda lo que se escribe a mano; los totales no se guardan, se calculan
 * siempre desde estas cifras, así que una hoja nunca puede mostrar un total que
 * no corresponda a sus propios números.
 */
class HojaCuadre extends ModeloBase
{
    use PerteneceAEmpresa;

    protected $table = 'hoja_cuadre';

    public const TURNOS = ['am', 'pm'];

    protected $fillable = [
        'fecha', 'turno', 'efectivo_inicial_centavos', 'ventas_lectura_centavos',
        'efectivo_cambio_centavos', 'tarjeta_centavos', 'ath_movil_centavos',
        'notas', 'usuario_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'efectivo_inicial_centavos' => 'integer',
            'ventas_lectura_centavos' => 'integer',
            'efectivo_cambio_centavos' => 'integer',
            'tarjeta_centavos' => 'integer',
            'ath_movil_centavos' => 'integer',
        ];
    }

    public const ORDENABLES = ['fecha', 'turno'];

    public function gastos(): HasMany
    {
        return $this->hasMany(GastoCuadre::class, 'hoja_id')->orderBy('orden');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    /** @return array{venta_y_cambio: int, total_efectivo: int, gastos: int, a_depositar: int, total_ventas: int} */
    public function totales(): array
    {
        return Cuadre::calcular([
            'efectivo_inicial' => $this->efectivo_inicial_centavos,
            'ventas_lectura' => $this->ventas_lectura_centavos,
            'efectivo_cambio' => $this->efectivo_cambio_centavos,
            'tarjeta' => $this->tarjeta_centavos,
            'ath_movil' => $this->ath_movil_centavos,
        ], $this->gastos->pluck('monto_centavos')->all());
    }
}
