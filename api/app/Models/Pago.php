<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cobro. No se edita ni se borra: si se cobró de menos se registra otro, y si
 * se cobró de más se devuelve (PR-02). La base le quita a la aplicación el
 * permiso de update y delete sobre esta tabla.
 */
class Pago extends ModeloBase
{
    use PerteneceAEmpresa;

    protected $table = 'pago';
    public $timestamps = false;

    /** Los métodos los administra la plataforma para que los reportes comparen (ADM-17). */
    public const METODOS = ['efectivo', 'ath_movil', 'tarjeta', 'transferencia', 'cheque', 'paypal', 'credito'];

    protected $fillable = [
        'documento_id', 'metodo', 'monto_centavos', 'recibido_centavos',
        'referencia', 'usuario_id',
    ];

    protected function casts(): array
    {
        return [
            'monto_centavos' => 'integer',
            'recibido_centavos' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class, 'documento_id');
    }

    /** El cambio que se le devuelve al cliente (FAC-07). */
    public function cambioCentavos(): int
    {
        if ($this->metodo !== 'efectivo' || $this->recibido_centavos === null) {
            return 0;
        }

        return max(0, $this->recibido_centavos - $this->monto_centavos);
    }
}
