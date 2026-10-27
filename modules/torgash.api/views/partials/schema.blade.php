{{--
    One JSON shape, printed as a table — and itself again for whatever nests
    inside it.

    Deliberately distinctive variable names ($schemaNode, $schemaDocument,
    $schemaDepth): an @include inherits EVERY variable in the caller's scope, so
    a partial that defaults an ordinarily-named one silently takes whatever a
    loop two hundred lines earlier left behind. See docs/LESSONS.md.
--}}
@php
    $schemaDepth = $schemaDepth ?? 0;

    // A $ref followed home, so a nested shape prints as itself rather than as a
    // pointer the reader has to go and look up.
    $follow = function (array $node) use ($schemaDocument) {
        return isset($node['$ref'])
            ? ($schemaDocument['components']['schemas'][basename($node['$ref'])] ?? [])
            : $node;
    };

    $schemaNode = $follow($schemaNode);
    $properties = $schemaNode['properties'] ?? [];

    $typeLabel = function (array $node) {
        $types = implode('|', (array) ($node['type'] ?? 'object'));

        return $node['format'] ?? null ? $types.', '.$node['format'] : $types;
    };
@endphp

@if ($properties)
    <div class="overflow-x-auto {{ $schemaDepth > 0 ? 'border-l-2 border-base-300 pl-3 mt-1' : '' }}">
        <table class="table table-xs">
            <tbody>
                @foreach ($properties as $propertyName => $property)
                    @php
                        $property = $follow($property);
                        // An array documents its items, an object its own
                        // properties — one column for both, because a reader
                        // wants the same thing from each.
                        $inner = $follow($property['items'] ?? []) ?: (isset($property['properties']) ? $property : []);
                    @endphp
                    <tr>
                        <td class="font-mono align-top whitespace-nowrap">{{ $propertyName }}</td>
                        <td class="align-top whitespace-nowrap text-xs opacity-50">{{ $typeLabel($property) }}</td>
                        <td class="opacity-75 align-top">
                            {{-- Inline markdown, so a `field` in a sentence reads
                                 as code rather than as backticks. HTML is
                                 ESCAPED: this prose comes from a module's own
                                 registration, and a description is not a place
                                 from which to reach into the admin panel. --}}
                            {!! \Illuminate\Support\Str::inlineMarkdown($property['description'] ?? '', ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
                            @if ($property['enum'] ?? null)
                                <code class="text-xs">{{ implode(' | ', $property['enum']) }}</code>
                            @endif
                            @if ($inner && $schemaDepth < 2)
                                @include('torgash.api::partials.schema', [
                                    'schemaNode' => $inner,
                                    'schemaDocument' => $schemaDocument,
                                    'schemaDepth' => $schemaDepth + 1,
                                ])
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@elseif (($schemaNode['additionalProperties'] ?? null) === true)
    <div class="text-xs opacity-50 italic">{{ __('An open object — this resource has not declared its fields.') }}</div>
@endif
