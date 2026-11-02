@php $k = $advisory?->id ?? 'new'; @endphp
<form method="POST" action="{{ $action }}" class="space-y-4">
    @csrf
    @if ($advisory) @method('PUT') @endif
    <x-field name="title" label="Title" for="a-title-{{ $k }}"><input id="a-title-{{ $k }}" name="title" value="{{ $advisory?->title }}" required class="input"></x-field>
    <x-field name="category" label="Category" for="a-cat-{{ $k }}"><input id="a-cat-{{ $k }}" name="category" value="{{ $advisory?->category }}" placeholder="e.g. Prevention" class="input"></x-field>
    <x-field name="summary" label="Summary" for="a-sum-{{ $k }}"><textarea id="a-sum-{{ $k }}" name="summary" rows="2" class="input">{{ $advisory?->summary }}</textarea></x-field>
    <x-field name="body" label="Body" for="a-body-{{ $k }}" hint="Plain text. Separate paragraphs with a blank line."><textarea id="a-body-{{ $k }}" name="body" rows="9" required class="input">{{ $advisory?->body }}</textarea></x-field>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_published" value="1" @checked($advisory?->is_published)> Published (visible to farmers)</label>
    <div class="flex justify-end"><button type="submit" class="btn btn-primary">Save</button></div>
</form>
