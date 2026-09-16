<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Documento extends ModeloBase
{
    use PerteneceAEmpresa;

    protected $table = 'documento';

    public const TIPOS = ['factura', 'cotizacion', 'nota_credito'];

    /** El estado se calcula a partir de lo cobrado; no se elige a mano (FAC-02). */
    public const ESTADOS = ['borrador', 'emitida', 'pagada_parcial', 'pagada', 'vencida', 'anulada'];

    protected $fillable = [
        'serie_id', 'cliente_id', 'tipo', 'estado', 'numero', 'folio',
        'cliente_nombre', 'cliente_exento', 'descuento_tipo', 'descuento_valor',
        'subtotal_centavos', 'descuento_centavos', 'base_centavos', 'impuesto_centavos',
        'total_centavos', 'pagado_centavos', 'impuesto_desglose',
        'terminos_pago', 'vence_el', 'notas', 'usuario_id', 'emitida_en',
        'anulada_en', 'motivo_anulacion',
    ];

    protected function casts(): array
    {
        return [
            'cliente_exento' => 'boolean',
            'subtotal_centavos' => 'integer',
            'descuento_centavos' => 'integer',
            'base_centavos' => 'integer',
            'impuesto_centavos' => 'integer',
            'total_centavos' => 'integer',
            'pagado_centavos' => 'integer',
            'impuesto_desglose' => 'array',
            'vence_el' => 'date',
            'emitida_en' => 'datetime',
            'anulada_en' => 'datetime',
        ];
    }

    public const ORDENABLES = ['numero', 'total_centavos', 'created_at'];

    public function renglones(): HasMany
    {
        return $this->hasMany(DocumentoRenglon::class, 'documento_id')->orderBy('orden');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'documento_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function serie(): BelongsTo
    {
        return $this->belongsTo(SerieDocumento::class, 'serie_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function saldoCentavos(): int
    {
        return max(0, $this->total_centavos - $this->pagado_centavos);
    }

    public function estaEmitida(): bool
    {
        return ! in_array($this->estado, ['borrador', 'anulada'], true);
    }

    /**
     * El estado sale de lo cobrado y de la fecha, nunca de una elección (FAC-02).
     */
    public function estadoSegunPagos(): string
    {
        if ($this->estado === 'anulada' || $this->estado === 'borrador') {
            return $this->estado;
        }

        if ($this->pagado_centavos >= $this->total_centavos && $this->total_centavos > 0) {
            return 'pagada';
        }

        if ($this->total_centavos === 0) {
            return 'pagada';
        }

        if ($this->vence_el && $this->vence_el->isPast()) {
            return 'vencida';
        }

        return $this->pagado_centavos > 0 ? 'pagada_parcial' : 'emitida';
    }

    public function scopeBuscar(Builder $consulta, ?string $texto): Builder
    {
        if (blank($texto)) {
            return $consulta;
        }

        $limpio = trim($texto);
        $patron = '%'.mb_strtolower($limpio).'%';

        return $consulta->where(function (Builder $q) use ($patron, $limpio) {
            $q->whereRaw('lower(coalesce(folio, \'\')) like ?', [$patron])
                ->orWhereRaw('lower(coalesce(cliente_nombre, \'\')) like ?', [$patron]);

            if (ctype_digit($limpio)) {
                $q->orWhere('numero', (int) $limpio);
            }
        });
    }
}
