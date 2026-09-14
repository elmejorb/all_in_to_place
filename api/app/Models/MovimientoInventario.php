<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un asiento de existencia. No se edita ni se borra: se corrige con otro
 * movimiento (INV-01, PR-02). La base misma le quita a la aplicación el permiso
 * de update y delete sobre esta tabla.
 */
class MovimientoInventario extends ModeloBase
{
    use PerteneceAEmpresa;

    protected $table = 'movimiento_inventario';
    public $timestamps = false;

    public const TIPOS = ['apertura', 'entrada', 'salida', 'venta', 'devolucion', 'ajuste', 'merma', 'traspaso'];

    /** Motivos de un ajuste manual, que siempre hay que declarar (INV-03). */
    public const MOTIVOS_AJUSTE = ['conteo', 'merma', 'rotura', 'vencido', 'regalo', 'error de captura', 'otro'];

    protected $fillable = [
        'producto_id', 'tipo', 'cantidad', 'existencia_resultante', 'costo_unitario_centavos',
        'motivo', 'comentario', 'usuario_id', 'referencia_tipo', 'referencia_id',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'existencia_resultante' => 'decimal:3',
            'costo_unitario_centavos' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
