<?php

/**
 * Mensajes de validación en español (RNF-05).
 *
 * Laravel trae solo inglés: sin este archivo la API contesta "The field must be
 * at least 5" aunque la empresa tenga el idioma en español. Los mensajes dicen
 * qué pasó y qué hacer, sin disculpas ni tecnicismos (PR-08).
 */
return [
    'accepted' => 'Tienes que aceptar :attribute.',
    'after' => ':Attribute tiene que ser una fecha posterior a :date.',
    'after_or_equal' => ':Attribute tiene que ser :date o una fecha posterior.',
    'alpha' => ':Attribute solo puede llevar letras.',
    'alpha_dash' => ':Attribute solo puede llevar letras, números, guiones y guiones bajos.',
    'alpha_num' => ':Attribute solo puede llevar letras y números.',
    'array' => ':Attribute tiene que ser una lista.',
    'before' => ':Attribute tiene que ser una fecha anterior a :date.',
    'before_or_equal' => ':Attribute tiene que ser :date o una fecha anterior.',
    'between' => [
        'array' => ':Attribute tiene que tener entre :min y :max elementos.',
        'file' => ':Attribute tiene que pesar entre :min y :max kilobytes.',
        'numeric' => ':Attribute tiene que estar entre :min y :max.',
        'string' => ':Attribute tiene que tener entre :min y :max caracteres.',
    ],
    'boolean' => ':Attribute solo puede ser sí o no.',
    'confirmed' => ':Attribute no coincide con la confirmación.',
    'current_password' => 'La contraseña no es correcta.',
    'date' => ':Attribute no es una fecha válida.',
    'date_equals' => ':Attribute tiene que ser :date.',
    'date_format' => ':Attribute no tiene el formato :format.',
    'decimal' => ':Attribute tiene que tener :decimal decimales.',
    'different' => ':Attribute y :other tienen que ser distintos.',
    'digits' => ':Attribute tiene que tener :digits dígitos.',
    'digits_between' => ':Attribute tiene que tener entre :min y :max dígitos.',
    'email' => ':Attribute no es un correo válido.',
    'ends_with' => ':Attribute tiene que terminar en: :values.',
    'exists' => ':Attribute no existe.',
    'file' => ':Attribute tiene que ser un archivo.',
    'filled' => ':Attribute no puede ir vacío.',
    'gt' => [
        'numeric' => ':Attribute tiene que ser mayor que :value.',
        'string' => ':Attribute tiene que tener más de :value caracteres.',
    ],
    'gte' => [
        'numeric' => ':Attribute tiene que ser :value o más.',
        'string' => ':Attribute tiene que tener :value caracteres o más.',
    ],
    'image' => ':Attribute tiene que ser una imagen.',
    'in' => ':Attribute no es una opción válida.',
    'integer' => ':Attribute tiene que ser un número entero.',
    'ip' => ':Attribute tiene que ser una dirección IP válida.',
    'json' => ':Attribute tiene que ser texto JSON válido.',
    'lt' => [
        'numeric' => ':Attribute tiene que ser menor que :value.',
        'string' => ':Attribute tiene que tener menos de :value caracteres.',
    ],
    'lte' => [
        'numeric' => ':Attribute tiene que ser :value o menos.',
        'string' => ':Attribute tiene que tener :value caracteres o menos.',
    ],
    'max' => [
        'array' => ':Attribute no puede tener más de :max elementos.',
        'file' => ':Attribute no puede pesar más de :max kilobytes.',
        'numeric' => ':Attribute no puede ser mayor que :max.',
        'string' => ':Attribute no puede pasar de :max caracteres.',
    ],
    'mimes' => ':Attribute tiene que ser un archivo de tipo: :values.',
    'mimetypes' => ':Attribute tiene que ser un archivo de tipo: :values.',
    'min' => [
        'array' => ':Attribute tiene que tener al menos :min elementos.',
        'file' => ':Attribute tiene que pesar al menos :min kilobytes.',
        'numeric' => ':Attribute tiene que ser al menos :min.',
        'string' => ':Attribute tiene que tener al menos :min caracteres.',
    ],
    'not_in' => ':Attribute no es una opción válida.',
    'numeric' => ':Attribute tiene que ser un número.',
    'present' => 'Falta :attribute.',
    'prohibited' => ':Attribute no se puede enviar.',
    'regex' => ':Attribute no tiene el formato esperado.',
    'required' => 'Falta :attribute.',
    'required_if' => 'Falta :attribute cuando :other es :value.',
    'required_with' => 'Falta :attribute cuando se envía :values.',
    'required_without' => 'Falta :attribute cuando no se envía :values.',
    'same' => ':Attribute y :other tienen que coincidir.',
    'size' => [
        'array' => ':Attribute tiene que tener :size elementos.',
        'file' => ':Attribute tiene que pesar :size kilobytes.',
        'numeric' => ':Attribute tiene que ser :size.',
        'string' => ':Attribute tiene que tener :size caracteres.',
    ],
    'starts_with' => ':Attribute tiene que empezar con: :values.',
    'string' => ':Attribute tiene que ser texto.',
    'unique' => 'Ya existe otro registro con ese :attribute.',
    'uploaded' => 'No se pudo subir :attribute.',
    'url' => ':Attribute tiene que ser una dirección web válida.',

    'custom' => [],

    'attributes' => [
        'email' => 'correo',
        'password' => 'contraseña',
        'nombres' => 'nombres',
        'apellidos' => 'apellidos',
        'empresa' => 'empresa',
        'buscar' => 'búsqueda',
        'estado' => 'estado',
        'orden' => 'orden',
        'direccion' => 'dirección',
        'por_pagina' => 'cantidad por página',
        'cursor' => 'cursor',
    ],
];
