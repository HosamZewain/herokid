@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'text-sm text-red-600 space-y-1']) }}>
        @foreach (\Illuminate\Support\Arr::flatten((array) $messages) as $message)
            @if (is_scalar($message))
                <li>{{ $message }}</li>
            @endif
        @endforeach
    </ul>
@endif
