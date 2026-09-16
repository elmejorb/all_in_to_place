<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un renglón de la factura. La descripción, el código y el precio se copian al
 * emitir: si mañana renombran o resuelven el producto, la factura de ayer sigue
 * diciendo lo que decía (RNF-12).
 */
class DocumentoRenglon extends ModeloBase
{
    use PerteneceAEmpresa;

    protected $table = 'documento_renglon';
    public $timestamps = false;

    protected $fillable = [
        'documento_id', 'producto_id', 'orden', 'descripcion', 'detalle', 'sku', 'unidad',
        'es_servicio', 'cantidad', 'precio_centavos', 'descuento_tipo', 'descuento_valor',
        'impuesto_milesimas', 'exento', 'bruto_centavos', 'descuento_centavos',
        'base_centavos', 'impuesto_centavos', 'total_centavos',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'es_servicio' => 'boolean',
            'exento' => 'boolean',
            'precio_centavos' => 'integer',
            'impuesto_milesimas' => 'integer',
            'bruto_centavos' => 'integer',
            'descuento_centavos' => 'integer',
            'base_centavos' => 'integer',
            'impuesto_centavos' => 'integer',
            'total_centavos' => 'integer',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
