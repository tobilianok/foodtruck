<form method="post" action="{{ $action }}" class="sl-period">
    @csrf
    @if ($method) @method($method) @endif
    <label class="field-inline">Du <input type="date" name="date_from" value="{{ old('date_from', $from) }}" required></label>
    <label class="field-inline">au <input type="date" name="date_to" value="{{ old('date_to', $to) }}" required></label>
    <button type="submit" class="btn">{{ $button }}</button>
    @error('date_from') <span class="alert alert-error">{{ $message }}</span> @enderror
    @error('date_to') <span class="alert alert-error">{{ $message }}</span> @enderror
</form>
