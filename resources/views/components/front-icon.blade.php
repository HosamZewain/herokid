@props(['name'])
{{-- Unmodified Heroicons Outline assets exported from @heroicons/react/24/outline. --}}
@if(in_array($name, ['arrow-left', 'bars-3', 'x-mark', 'book-open', 'chevron-down', 'cube', 'envelope', 'eye', 'magnifying-glass', 'pencil', 'shopping-cart', 'user-circle'], true))
    <img {{ $attributes->merge(['class' => 'hk-icon']) }} src="{{ asset('images/icons/heroicons/'.$name.'.svg') }}" alt="" aria-hidden="true" width="24" height="24">
@endif
