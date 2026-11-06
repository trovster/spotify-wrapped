<div class="period-options">
    @foreach($options as $option)
        <label class="period-option"><input type="radio" name="period" value="{{ $option['value'] }}" @checked($selected === $option['value'])><span>{{ $option['label'] }}</span></label>
    @endforeach
</div>
