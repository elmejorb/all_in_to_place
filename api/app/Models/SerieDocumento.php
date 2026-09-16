<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;

class SerieDocumento extends ModeloBase
{
    use PerteneceAEmpresa;

    protected $table = 'serie_documento';

    protected $fillable = ['tipo', 'nombre', 'prefijo', 'proximo_numero', 'predeterminada'];

    protected function casts(): array
    {
        return ['proximo_numero' => 'integer', 'predeterminada' => 'boolean'];
    }

    public function folio(int $numero): string
    {
        return ($this->prefijo ? $this->prefijo.'-' : '').str_pad((string) $numero, 5, '0', STR_PAD_LEFT);
    }
}
