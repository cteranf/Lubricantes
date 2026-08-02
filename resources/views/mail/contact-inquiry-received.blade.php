@component('mail::message')
# Nueva consulta de contacto

**Referencia:** {{ $inquiry->public_id }}  
**Fecha:** {{ $inquiry->created_at?->format('Y-m-d H:i:s') }}  
**Nombre:** {{ $inquiry->name }}  
**Correo:** {{ $inquiry->email }}  
@if($inquiry->phone)
**Teléfono:** {{ $inquiry->phone }}  
@endif
**Asunto:** {{ $inquiry->subject }}

## Mensaje

@foreach(explode("\n", $inquiry->message) as $line)
{{ $line }}  
@endforeach

@component('mail::button', ['url' => $adminUrl])
Abrir bandeja de consultas
@endcomponent

Este aviso fue generado después de guardar la consulta.
@endcomponent
