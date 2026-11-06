@extends('layout')

@section('title', 'Setup guide · Spotify Wrapped')

@section('content')
<main class="container guide-page">
    <p class="eyebrow">Your setup companion</p>
    <article class="guide-copy">{!! $content !!}</article>
</main>
@endsection
