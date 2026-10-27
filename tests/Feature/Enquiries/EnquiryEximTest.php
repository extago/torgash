<?php

namespace Tests\Feature\Enquiries;

use Tests\TestCase;
use Torgash\Enquiries\Models\Enquiry;

/**
 * §23.4/4: the export half of a CRM hookup. No exim.match.enquiry is
 * registered on purpose — an enquiry has no natural key an outside system
 * would already know it by, so there is nothing to import against.
 */
class EnquiryEximTest extends TestCase
{
    public function test_the_entity_is_registered(): void
    {
        $this->assertArrayHasKey('enquiry', hook_filter('exim.entities', [], []));
    }

    public function test_the_query_answers_with_every_enquiry(): void
    {
        Enquiry::create(['kind' => 'callback', 'phone' => '1']);
        Enquiry::create(['kind' => 'message', 'phone' => '2']);

        $query = hook_filter('exim.query.enquiry', null, ['filters' => []]);

        $this->assertSame(2, $query->count());
    }

    public function test_the_schema_flattens_the_answers_with_their_labels(): void
    {
        $enquiry = Enquiry::create([
            'kind' => 'callback', 'phone' => '+380671234567', 'name' => 'Иван',
            'fields' => ['values' => ['phone' => '+380671234567'], 'labels' => ['phone' => 'Phone']],
        ]);

        $fields = collect(hook_filter('exim.schema.enquiry', []))->keyBy('key');

        $this->assertSame('Callback', ($fields['kind']['get'])($enquiry));
        $this->assertSame('Phone: +380671234567', ($fields['answers']['get'])($enquiry));

        // Read-only: an exported row cannot be replayed back into the shop.
        foreach ($fields as $field) {
            $this->assertNull($field['set']);
        }
    }
}
