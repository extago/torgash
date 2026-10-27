<?php

namespace Tests\Feature\Core;

use App\Models\Currency;
use App\Models\User;
use Tests\TestCase;

/**
 * The currency a storefront keeps its books in is load-bearing — every stored
 * amount is in it — so the screen can neither switch it off nor delete it,
 * whichever door is used.
 */
class CurrenciesScreenTest extends TestCase
{
    protected function books(): Currency
    {
        return Currency::where('code', shop_currency())->firstOrFail();
    }

    public function test_the_books_currency_cannot_be_deleted_or_switched_off(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $books = $this->books();

        $this->actingAs($admin)
            ->delete('http://localhost/admin/currencies/'.$books->id)
            ->assertSessionHasErrors('code');

        $this->actingAs($admin)
            ->patch('http://localhost/admin/currencies/'.$books->id.'/status', ['enabled' => 0])
            ->assertStatus(422);

        $this->assertTrue($books->fresh()->enabled);
    }

    public function test_another_currency_is_switched_in_place(): void
    {
        $euro = Currency::where('code', 'EUR')->firstOrFail();
        $euro->update(['enabled' => true]);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->patch('http://localhost/admin/currencies/'.$euro->id.'/status', ['enabled' => 0])
            ->assertOk()
            ->assertSee('currency-status-'.$euro->id, false);

        $this->assertFalse($euro->fresh()->enabled);
    }

    public function test_saving_stays_on_the_form(): void
    {
        $euro = Currency::where('code', 'EUR')->firstOrFail();

        $this->actingAs(User::role('admin')->firstOrFail())
            ->put('http://localhost/admin/currencies/'.$euro->id, [
                'code' => 'EUR', 'symbol' => '€', 'name' => 'Euro', 'rate' => 0.9, 'decimals' => 2,
                'symbol_position' => 'after_space', 'decimal_separator' => ',', 'thousands_separator' => 'space',
                'enabled' => 1, 'auto_rate' => 1,
            ])
            ->assertRedirect(route('admin.currencies.edit', $euro));
    }
}
