<section class="stats-panel list-panel list-panel--{{ $id }}" aria-labelledby="{{ $id }}-title">
    <div class="stats-panel__heading"><h2 id="{{ $id }}-title">{{ $title }}</h2>@if($seeAll)<a class="text-link" href="/stats/{{ $id }}?period={{ $period->value }}">See all <span aria-hidden="true">↗</span></a>@endif</div>
    @if(count($rows) >= 3 && !empty($rows[0]['image']) && !empty($rows[1]['image']) && !empty($rows[2]['image']))
        <div class="cover-collage {{ $id === 'artists' ? 'cover-collage--round' : '' }}" aria-hidden="true">
            @foreach(array_slice($rows, 0, 3) as $row)<img src="{{ $row['image'] }}" alt="" loading="lazy">@endforeach
        </div>
    @endif
    @if(empty($rows))
        <p class="list-empty">Not enough plays in this period.</p>
    @else
        <ol class="ranked-list">
            @foreach($rows as $row)
                <li>
                    <span class="ranked-list__rank">{{ $row['rank'] }}</span>
                    @if(!empty($row['image']))<img class="ranked-list__image {{ $id === 'artists' ? 'ranked-list__image--round' : '' }}" src="{{ $row['image'] }}" alt="" loading="lazy">@else<span class="ranked-list__image ranked-list__placeholder {{ $id === 'artists' ? 'ranked-list__image--round' : '' }}" data-theme="{{ $loop->index % 2 === 0 ? 'violet' : 'ember' }}" aria-hidden="true">{{ mb_substr($row['name'], 0, 1) }}</span>@endif
                    <span class="ranked-list__copy"><strong>{{ $row['name'] }}</strong>@if(!empty($row['sub']))<small>{{ $row['sub'] }}</small>@endif @if($id === 'genres' && isset($row['v']))<span class="ranked-list__bar" aria-hidden="true"><span style="--v: {{ $row['v'] }}"></span></span>@endif</span>
                    <span class="ranked-list__count">{{ number_format($row['plays']) }} <span class="sr-only">plays</span></span>
                </li>
            @endforeach
        </ol>
    @endif
</section>
