@if ($previous->isNotEmpty())
    <section class="panel">
        <h2>Listes précédentes</h2>
        <ul class="frozen-list">
            @foreach ($previous as $old)
                <li>
                    <span><a href="{{ route('shopping.show', $old) }}">Courses du {{ $old->periodLabel() }}</a>
                        <span class="muted small">· {{ $old->items()->count() }} articles</span></span>
                    <form method="post" action="{{ route('shopping.reopen', $old) }}">
                        @csrf
                        <button type="submit" class="btn btn-small btn-ghost">Rouvrir</button>
                    </form>
                </li>
            @endforeach
        </ul>
    </section>
@endif
