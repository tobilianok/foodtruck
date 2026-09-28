@if (session('status'))
    <div class="alert alert-ok" role="status">{{ session('status') }}</div>
@endif

@if ($errors->any())
    <div class="alert alert-error" role="alert">
        @if ($errors->count() === 1)
            {{ $errors->first() }}
        @else
            <p>Quelques points à corriger :</p>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
