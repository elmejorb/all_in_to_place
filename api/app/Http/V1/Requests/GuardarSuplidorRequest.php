<?php

namespace App\Http\V1\Requests;

use App\Models\Suplidor;
use App\Soporte\ContextoRls;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Esquema explícito de lo que se acepta. Lo que no está aquí se descarta:
 * nunca se asigna en masa lo que venga en la petición (SEG-13).
 */
class GuardarSuplidorRequest extends FormRequest
{
    public function rules(): array
    {
        $suplidor = $this->route('suplidor');

        return [
            'razon_social' => ['required', 'string', 'min:2', 'max:150'],
            'nombre_comercial' => ['nullable', 'string', 'max:150'],
            'numero_cliente' => [
                'nullable', 'string', 'max:60',
                // Único dentro de la empresa, no de la plataforma (SUP-02).
                Rule::unique(Suplidor::class, 'numero_cliente')
                    ->where('empresa_id', ContextoRls::empresaActual())
                    ->whereNull('borrado_en')
                    ->ignore($suplidor?->id),
            ],
            'telefono' => ['nullable', 'string', 'max:30', 'regex:/^[0-9 ()+\-.]{7,30}$/'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'vendedor' => ['nullable', 'string', 'max:120'],
            'terminos_pago' => ['nullable', 'string', 'max:60'],
            'notas' => ['nullable', 'string', 'max:2000'],
            'activo' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'razon_social' => 'razón social',
            'nombre_comercial' => 'nombre comercial',
            'numero_cliente' => 'número de cliente',
            'terminos_pago' => 'términos de pago',
        ];
    }

    public function messages(): array
    {
        return [
            'telefono.regex' => 'El teléfono solo puede llevar números, espacios, paréntesis, guiones y el signo más.',
            'numero_cliente.unique' => 'Ya tienes otro suplidor con ese número de cliente.',
        ];
    }
}
