<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends ModeloBase
{
    use PerteneceAEmpresa, SoftDeletes;

    protected $table = 'cliente';
    public const DELETED_AT = 'borrado_en';

    public const TIPOS = ['persona', 'empresa'];

    protected $fillable = [
        'nombre', 'tipo', 'identificacion', 'telefono', 'email', 'direccion',
        'exento', 'certificado_exencion', 'terminos_pago', 'limite_credito_centavos',
        'notas', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'exento' => 'boolean',
            'activo' => 'boolean',
            'limite_credito_centavos' => 'integer',
        ];
    }

    public const ORDENABLES = ['nombre', 'created_at'];

    public function scopeBuscar(Builder $consulta, ?string $texto): Builder
    {
        if (blank($texto)) {
            return $consulta;
        }

        $limpio = trim($texto);
        $patron = '%'.mb_strtolower($limpio).'%';

        // Solo los dígitos, para encontrar un teléfono escrito de cualquier
        // forma. Ojo: si la búsqueda no trae números esto queda vacío, y un
        // LIKE '%%' devolvería la tabla entera. Por eso la condición del
        // teléfono solo se agrega cuando hay dígitos que comparar.
        $soloDigitos = preg_replace('/[^0-9]/', '', $limpio) ?? '';

        return $consulta->where(function (Builder $q) use ($patron, $soloDigitos) {
            $q->whereRaw('lower(nombre) like ?', [$patron])
                ->orWhereRaw('lower(coalesce(identificacion, \'\')) like ?', [$patron])
                ->orWhereRaw('lower(coalesce(email, \'\')) like ?', [$patron]);

            if ($soloDigitos !== '') {
                $q->orWhereRaw(
                    "regexp_replace(coalesce(telefono, ''), '[^0-9]', '', 'g') like ?",
                    ['%'.$soloDigitos.'%'],
                );
            }
        });
    }

    public function tieneCredito(): bool
    {
        return $this->limite_credito_centavos > 0;
    }
}
