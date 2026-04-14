@if ($state === 'locked')
    <div>
        <form method="POST" action="/docs/{{ $link->token }}/unlock">
            @csrf
            <label>Email <input type="email" name="email" required></label>
            <label><input type="checkbox" name="dsgvo" required> Ich stimme zu, dass meine Daten verarbeitet werden.</label>
            <button type="submit">Unterlagen ansehen</button>
        </form>
    </div>
@else
    <div>
        @foreach ($files as $file)
            <a href="/docs/{{ $link->token }}/file/{{ $file->id }}/view">{{ $file->label ?: $file->filename }}</a>
        @endforeach
    </div>
@endif
