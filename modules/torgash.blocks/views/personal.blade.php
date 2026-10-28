{{-- Blocks of a zone that are the visitor's own, kept in a page made ready
     for guests as a personal part (PLAN §61) and drawn here again for each
     visitor — the same blocks, with the same context, as ZoneRenderer drew
     them for the first. --}}
{!! app(\Torgash\Blocks\ZoneRenderer::class)->render($blocks, $context) !!}
