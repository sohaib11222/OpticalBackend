{{ $brand }}

{{ $headline }}

@if(!empty($greeting)){{ $greeting }}

@endif{{ $body ?? '' }}
@if(!empty($code))

Codice: {{ $code }}
@endif
@if(!empty($action_label))

{{ $action_label }}
@endif
@if(!empty($note))

{{ $note }}
@endif

{{ $brand }} · Comunicazione di servizio
