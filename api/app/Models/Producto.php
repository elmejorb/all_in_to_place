<?php

namespace App\Models;

use App\Domain\Precio;
use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Producto extends ModeloBase
{
    use PerteneceAEmpresa, SoftDeletes;

    protected $table = 'producto';
    public const DELETED_AT = 'borrado_en';

    public const UNIDADES = ['unidad', 'libra', 'onza', 'kilo', 'litro', 'galon', 'caja', 'docena', 'paquete', 'servicio'];

    /**
     * `existencia` no está aquí a propósito: es caché de los movimientos y solo
     * la escribe el registrador de inventario (INV-02).
     */
    protected $fillable = [
        'nombre', 'sku', 'codigo_barras', 'descripcion', 'categoria_id', 'suplidor_id',
        'unidad', 'costo_centavos', 'precio_centavos', 'impuesto_milesimas',
        'existencia_minima', 'es_servicio', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'costo_centavos' => 'integer',
            'precio_centavos' => 'integer',
            'impuesto_milesimas' => 'integer',
            'existencia' => 'decimal:3',
            'existencia_minima' => 'decimal:3',
            'es_servicio' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public const ORDENABLES = ['nombre', 'sku', 'precio_centavos', 'costo_centavos', 'existencia', 'created_at'];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function suplidor(): BelongsTo
    {
        return $this->belongsTo(Suplidor::class, 'suplidor_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class, 'producto_id');
    }

    public function margen(): ?float
    {
        return Precio::margen($this->costo_centavos, $this->precio_centavos);
    }

    public function vendeBajoCosto(): bool
    {
        return Precio::vendeBajoCosto($this->costo_centavos, $this->precio_centavos);
    }

    /** agotado | bajo | normal. Los servicios no tienen existencia (PRO-03). */
    public function estadoExistencia(): ?string
    {
        if ($this->es_servicio) {
            return null;
        }

        $existencia = (float) $this->existencia;

        if ($existencia <= 0) {
            return 'agotado';
        }

        return $existencia <= (float) $this->existencia_minima ? 'bajo' : 'normal';
    }

    public function scopeBuscar(Builder $consulta, ?string $texto): Builder
    {
        if (blank($texto)) {
            return $consulta;
        }

        $limpio = trim($texto);
        $patron = '%'.mb_strtolower($limpio).'%';

        return $consulta->where(function (Builder $q) use ($patron, $limpio) {
            $q->whereRaw('lower(nombre) like ?', [$patron])
                ->orWhereRaw('lower(coalesce(sku, \'\')) like ?', [$patron])
                // El lector de códigos escribe el código exacto (PRO-05).
                ->orWhere('codigo_barras', $limpio);
        });
    }

    public function scopeConExistencia(Builder $consulta, ?string $estado): Builder
    {
        return match ($estado) {
            'agotado' => $consulta->where('es_servicio', false)->where('existencia', '<=', 0),
            'bajo' => $consulta->where('es_servicio', false)
                ->whereColumn('existencia', '<=', 'existencia_minima')
                ->where('existencia', '>', 0),
            'normal' => $consulta->where('es_servicio', false)->whereColumn('existencia', '>', 'existencia_minima'),
            default => $consulta,
        };
    }
}
