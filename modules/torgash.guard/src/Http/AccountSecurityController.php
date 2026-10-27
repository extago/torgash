<?php

namespace Torgash\Guard\Http;

/**
 * A customer's own second factor, in their account area.
 *
 * The same five acts as the staff screen and the same code behind them; what
 * differs is that this one is drawn in the storefront's theme, beside their
 * addresses and their orders, and that the shop may not be offering it at all
 * (`two_factor_customers` is off out of the box) — in which case the route
 * answers 404 and the menu item is not there to click.
 */
class AccountSecurityController extends SecurityScreen
{
    protected function view(): string
    {
        return 'torgash.guard::account.security';
    }

    protected function route(): string
    {
        return 'account.security';
    }
}
