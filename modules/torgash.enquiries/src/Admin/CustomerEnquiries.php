<?php

namespace Torgash\Enquiries\Admin;

use Illuminate\Support\Facades\DB;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\Status;

/**
 * What the admin's customer screens are told about a customer's ENQUIRIES —
 * checkout's `CustomerOrders` for the other half of what a person does in a
 * shop, answering the same two hooks.
 *
 * By `user_id` alone, never by email: the inbox refuses to stitch threads to
 * an account by address (whoever registers with it would inherit a guest's
 * conversation), and the card is the same question asked from the other side.
 */
class CustomerEnquiries
{
    public function register(): void
    {
        hook_listen('core.admin.customers.facts', function (array $facts, array $context) {
            if (! (auth()->user()?->can('enquiries.manage'))) {
                return $facts;
            }

            foreach ($this->facts((array) ($context['ids'] ?? [])) as $userId => $rows) {
                $facts[$userId] = [...($facts[$userId] ?? []), ...$rows];
            }

            return $facts;
        });

        hook_listen('core.admin.customer.panels', function (array $panels, array $context) {
            $customer = $context['customer'] ?? null;

            if (! $customer || ! auth()->user()?->can('enquiries.manage')) {
                return $panels;
            }

            return [...$panels, ['key' => 'enquiries', 'position' => 20, 'html' => $this->panel($customer)]];
        });

        // The thread is the shop's record of what was asked and answered; the
        // enquiry already carries the name and contacts it was written with.
        hook_listen('core.admin.customer.delete_notes', function (array $notes, array $context) {
            $count = ($customer = $context['customer'] ?? null) ? Enquiry::where('user_id', $customer->id)->count() : 0;

            return $count === 0 ? $notes : [...$notes, ['key' => 'enquiries', 'deleted' => false,
                'label' => trans_choice(':count enquiry stays in the inbox, with the contacts it was sent with.|:count enquiries stay in the inbox, with the contacts they were sent with.', $count, ['count' => $count])]];
        });
    }

    /**
     * One query for a page of the list. Open ones are the hint: a customer the
     * shop still owes an answer to is what a manager wants to see beside them.
     *
     * @param  list<int>  $ids
     * @return array<int, list<array{key: string, label: string, value: string, hint?: string, url?: string}>>
     */
    public function facts(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $marks = implode(', ', array_fill(0, count(Status::OPEN), '?'));
        $rows = DB::table('m_enquiries_enquiries')
            ->whereIn('user_id', $ids)
            ->groupBy('user_id')
            ->selectRaw("user_id, count(*) as total, count(*) filter (where status in ({$marks})) as open", Status::OPEN)
            ->get();

        $facts = [];

        foreach ($rows as $row) {
            $fact = [
                'key' => 'enquiries',
                'label' => trans_choice(':count enquiry|:count enquiries', (int) $row->total, ['count' => (int) $row->total]),
                'value' => '',
                'url' => route('admin.enquiries.index', ['customer' => $row->user_id, 'view' => 'all']),
            ];
            if ((int) $row->open > 0) {
                $fact['value'] = trans_choice(':count open|:count open', (int) $row->open, ['count' => (int) $row->open]);
            }
            $facts[(int) $row->user_id] = [$fact];
        }

        return $facts;
    }

    protected function panel($customer): string
    {
        $query = Enquiry::where('user_id', $customer->id);

        return view('torgash.enquiries::admin.customer', [
            'customer' => $customer,
            'enquiries' => (clone $query)->with('lastMessage')->latest('id')->limit(10)->get(),
            'count' => (clone $query)->count(),
            'open' => (clone $query)->open()->count(),
        ])->render();
    }
}
