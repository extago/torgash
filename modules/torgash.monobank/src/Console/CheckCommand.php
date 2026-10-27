<?php

namespace Torgash\Monobank\Console;

use Illuminate\Console\Command;
use Torgash\Monobank\Client;
use Torgash\Monobank\MonobankGateway;
use Torgash\Payments\Models\Binding;

/**
 * Does the token work, and is the shop reachable?
 *
 * Setting up an acquiring integration fails in three places and the failures
 * look alike from the order screen: a token that is not accepted, a public key
 * that cannot be fetched (so no callback will ever verify), and a callback URL
 * the bank cannot reach. This asks all three out loud, before a customer does.
 */
class CheckCommand extends Command
{
    protected $signature = 'monobank:check {--token= : use this token instead of the one on the binding}
                                           {--amount=100 : test invoice, in minor units}';

    protected $description = 'Check the monobank token, the signing key and the callback address';

    public function handle(): int
    {
        $binding = Binding::where('gateway', MonobankGateway::key())->first();
        $token = (string) ($this->option('token') ?: ($binding?->config['token'] ?? ''));

        if ($token === '') {
            $this->error('No token: wire a payment method to monobank in Admin → Payment gateways, or pass --token.');

            return self::FAILURE;
        }

        $client = new Client($token);

        // 1. The token itself, and the key every callback is checked against.
        $pubkey = $client->get('pubkey');

        if (! $pubkey->successful()) {
            $this->error('The bank refused the token: HTTP '.$pubkey->status().' '.$pubkey->body());

            return self::FAILURE;
        }

        $key = base64_decode((string) $pubkey->json('key'), true);
        $this->info('Token accepted. Signing key: '.(str_contains((string) $key, 'BEGIN PUBLIC KEY') ? 'looks right' : 'UNEXPECTED FORMAT'));

        // 2. A real invoice, which is the only way to know the account can
        //    actually take money.
        $invoice = $client->post('invoice/create', [
            'amount' => (int) $this->option('amount'),
            'ccy' => 980,
            'webHookUrl' => url('/payment/'.MonobankGateway::key().'/callback'),
        ]);

        if (! $invoice->successful()) {
            $this->error('Invoice refused: HTTP '.$invoice->status().' '.$invoice->body());

            return self::FAILURE;
        }

        $this->info('Test invoice created: '.$invoice->json('pageUrl'));

        // 3. The address the bank will call back on. It is printed rather than
        //    tested because only the bank can prove it is reachable — and a
        //    .local domain never is.
        $callback = url('/payment/'.MonobankGateway::key().'/callback');
        $this->line('Callback address sent with every invoice: '.$callback);

        if (preg_match('/\.local|localhost|127\.0\.0\.1/i', $callback)) {
            $this->warn('That address is not reachable from the internet, so no callback will arrive.');
            $this->warn('Pay the invoice above and the money moves — but the order will stay unpaid until');
            $this->warn('the shop is on a public host, or a tunnel points at it (APP_URL decides this URL).');
        }

        return self::SUCCESS;
    }
}
