<?php

namespace App\Http\V1\Requests;

use App\Domain\Precio;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Suplidor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Esquema explícito de lo que se acepta (SEG-13).
 *
 * El dinero llega como texto ("5.99") y sale de aquí convertido a centavos
 * enteros: la aplicación no maneja importes en coma flotante (ARQ-09).
 */
class GuardarProductoRequest extends FormRequest
{
    public function rules(): array
    {
        $producto = $this->route('producto');
        $esServicio = $this->boolean('es_servicio');

        return [
            'nombre' => ['required', 'string', 'min:2', 'max:150'],
            'sku' => ['nullable', 'string', 'max:60'],
            'codigo_barras' => ['nullable', 'string', 'max:60'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'categoria' => ['nullable', 'string', 'size:26'],
            'suplidor' => ['nullable', 'string', 'size:26'],
            'unidad' => ['nullable', Rule::in(Producto::UNIDADES)],
            'costo' => ['nullable', 'string', 'max:20'],
            'precio' => ['nullable', 'string', 'max:20'],
            'impuesto' => ['nullable', 'string', 'max:10'],
            // Un servicio no lleva existencia: ni se pide ni se acepta (PRO-03).
            'existencia_minima' => [Rule::excludeIf($esServicio), 'nullable', 'numeric', 'min:0', 'max:9999999'],
            'existencia_inicial' => [Rule::excludeIf($esServicio || $producto !== null), 'nullable', 'numeric', 'min:0', 'max:9999999'],
            'es_servicio' => ['boolean'],
            'activo' => ['boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $producto = $this->route('producto');

            foreach (['costo' => 'costo', 'precio' => 'precio de venta'] as $campo => $nombre) {
                $valor = $this->input($campo);
                if ($valor !== null && $valor !== '' && Precio::aCentavos($valor) === null) {
                    $validator->errors()->add($campo, "El {$nombre} tiene que ser un número.");
                }
            }

            if ($this->filled('impuesto') && Precio::tasaAMilesimas($this->input('impuesto')) === null) {
                $validator->errors()->add('impuesto', 'El impuesto tiene que ser un número.');
            }

            // SKU y código de barras únicos dentro de la empresa (PRO-01).
            foreach (['sku', 'codigo_barras'] as $campo) {
                if (! $this->filled($campo)) {
                    continue;
                }

                $repetido = Producto::query()
                    ->when($producto, fn ($q) => $q->where('id', '!=', $producto->id))
                    ->when(
                        $campo === 'sku',
                        fn ($q) => $q->whereRaw('lower(sku) = ?', [mb_strtolower(trim((string) $this->input('sku')))]),
                        fn ($q) => $q->where('codigo_barras', trim((string) $this->input('codigo_barras'))),
                    )
                    ->exists();

                if ($repetido) {
                    $validator->errors()->add(
                        $campo,
                        $campo === 'sku'
                            ? 'Ya tienes otro producto con ese código interno.'
                            : 'Ya tienes otro producto con ese código de barras.',
                    );
                }
            }

            // La categoría y el suplidor tienen que ser de esta empresa. Si no lo
            // son, la consulta no los encuentra y se responde igual que si no
            // existieran (ARQ-21).
            if ($this->filled('categoria') && ! Categoria::query()->where('ulid', $this->input('categoria'))->exists()) {
                $validator->errors()->add('categoria', 'Esa categoría no existe.');
            }

            if ($this->filled('suplidor') && ! Suplidor::query()->where('ulid', $this->input('suplidor'))->exists()) {
                $validator->errors()->add('suplidor', 'Ese suplidor no existe.');
            }
        });
    }

    /** Lo que se guarda, ya convertido. */
    public function datosDelProducto(): array
    {
        $datos = [
            'nombre' => $this->input('nombre'),
            'sku' => $this->input('sku') ?: null,
            'codigo_barras' => $this->input('codigo_barras') ?: null,
            'descripcion' => $this->input('descripcion') ?: null,
            'unidad' => $this->input('unidad') ?: 'unidad',
            'costo_centavos' => Precio::aCentavos($this->input('costo')) ?? 0,
            'precio_centavos' => Precio::aCentavos($this->input('precio')) ?? 0,
            'impuesto_milesimas' => Precio::tasaAMilesimas($this->input('impuesto')) ?? 0,
            'es_servicio' => $this->boolean('es_servicio'),
            'categoria_id' => $this->filled('categoria')
                ? Categoria::query()->where('ulid', $this->input('categoria'))->value('id')
                : null,
            'suplidor_id' => $this->filled('suplidor')
                ? Suplidor::query()->where('ulid', $this->input('suplidor'))->value('id')
                : null,
        ];

        if (! $this->boolean('es_servicio')) {
            $datos['existencia_minima'] = (float) ($this->input('existencia_minima') ?? 0);
        } else {
            $datos['existencia_minima'] = 0;
        }

        return $datos;
    }

    public function attributes(): array
    {
        return [
            'codigo_barras' => 'código de barras',
            'existencia_minima' => 'existencia mínima',
            'existencia_inicial' => 'existencia inicial',
            'descripcion' => 'descripción',
        ];
    }
}
