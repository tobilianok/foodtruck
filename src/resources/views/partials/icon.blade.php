{{-- Icônes simples (trait 2, sans dépendance). Usage : @include('partials.icon', ['name' => 'home']) --}}
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('home')<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9.5h13V10"/><path d="M10 19.5v-5h4v5"/>@break
        @case('calendar')<rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4"/>@break
        @case('cart')<path d="M3 4h2.4l2.2 10.2a1.5 1.5 0 0 0 1.5 1.2h8.2a1.5 1.5 0 0 0 1.5-1.1L20.5 8H6.2"/><circle cx="9.5" cy="19.5" r="1.4"/><circle cx="17" cy="19.5" r="1.4"/>@break
        @case('book')<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15.5H6.5A2.5 2.5 0 0 0 4 21z"/><path d="M4 5.5V21M8.5 7.5h7"/>@break
        @case('more')<circle cx="5.5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="18.5" cy="12" r="1.6"/>@break
        @case('fridge')<rect x="5.5" y="2.5" width="13" height="19" rx="3"/><path d="M5.5 10.5h13M9 6v2M9 13v3"/>@break
        @case('receipt')<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>@break
        @case('tag')<path d="M3 12.5V4h8.5L21 13.5 13.5 21z"/><circle cx="7.5" cy="8.5" r="1.3"/>@break
        @case('users')<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><path d="M16 5.2a3 3 0 0 1 0 5.6M18 14.8c1.8.7 3 2.2 3 4.7"/>@break
        @case('help')<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.6 2.2c-.8.4-1.1 1-1.1 1.8"/><circle cx="12" cy="17" r=".6" fill="currentColor"/>@break
        @case('logout')<path d="M9 4H5.5A1.5 1.5 0 0 0 4 5.5v13A1.5 1.5 0 0 0 5.5 20H9"/><path d="M16 8l4 4-4 4M20 12H9"/>@break
        @case('check')<path d="M5 12.5l4.5 4.5L19 7.5"/>@break
        @case('plate')<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/>@break
        @default<circle cx="12" cy="12" r="9"/>
    @endswitch
</svg>
