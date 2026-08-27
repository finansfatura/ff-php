<?php

declare(strict_types=1);

namespace Finansfatura;

// Builders for the two-layer issue payload, with the exact field casing the API
// expects.
//
// Gotcha the API imposes: the outer layer is snake_case (`document_type`,
// `canonical`) but everything inside `canonical` is PascalCase (`Recipient`,
// `Lines`, `Totals`, `VKNorTCKN` …). A snake_case key inside `canonical` is
// silently ignored. These builders encode that so callers never get it wrong.
//
// Totals are computed from the lines with exact decimal (bcmath, integer-string)
// arithmetic to avoid float kuruş drift; `LineTotal` is the KDV-excl net used
// verbatim by the server.
final class Payload
{
    /**
     * Generic builder. `$recipient`/`$issuer` are arrays with `vkn_tckn` +
     * optional `title`/`address`/`tax_office`/`email`/`phone`. `$lines` are
     * arrays with `title`/`qty`/`unit_price`/`vat_rate` (+ optional
     * `product_code`/`unit_code`).
     *
     * `transactionHeaderId` is REQUIRED — the `transaction_id` returned by
     * `Client::createOrder()`. Every document hangs off a sale: that is what
     * feeds the turnover report, the current account and stock. The server
     * rejects a sale-less document for API clients too; this throws before the
     * request so you don't burn a round trip.
     *
     * The one exception is a refund (`invoiceTypeCode` = `IADE`): attaching it to
     * the sale would count that sale twice, so it is issued unattached.
     *
     * Leave `recipientAlias` empty unless you know the mailbox handle: the server
     * looks the VKN up at GİB and upgrades `EARSIV` to `EFATURA` (with the right
     * alias) when the recipient turns out to be registered.
     *
     * Do NOT pass `issuer` in production — the server fills seller identity from
     * the company profile. It exists only for testing before the profile VKN is
     * set.
     *
     * $opts keys: transactionHeaderId(string), issuer(array),
     * recipientAlias(string), invoiceTypeCode(string, default "SATIS"),
     * note(string).
     *
     * @param array<string,mixed>        $recipient
     * @param array<int,array<string,mixed>> $lines
     * @param array<string,mixed>        $opts
     * @return array{document_type:string,canonical:array<string,mixed>}
     */
    public static function build(string $documentType, array $recipient, array $lines, array $opts = []): array
    {
        $invoiceTypeCode = (string) ($opts['invoiceTypeCode'] ?? 'SATIS');
        if (empty($opts['transactionHeaderId']) && strtoupper($invoiceTypeCode) !== 'IADE') {
            throw new \InvalidArgumentException(
                'transactionHeaderId is required — create the sale first with createOrder() and pass its transaction_id'
            );
        }
        ['canon' => $canon, 'totals' => $totals] = self::linesAndTotals($lines);

        $canonical = [
            'DocumentType' => $documentType,
            'InvoiceTypeCode' => $invoiceTypeCode,
            'Currency' => 'TRY',
            'RecipientAlias' => (string) ($opts['recipientAlias'] ?? ''),
            'Recipient' => self::party($recipient),
            'Lines' => $canon,
            'Totals' => $totals,
        ];
        if (isset($opts['issuer'])) {
            $canonical['Issuer'] = self::party($opts['issuer']);
        }
        if (!empty($opts['note'])) {
            $canonical['Note'] = $opts['note'];
        }

        $payload = ['document_type' => $documentType, 'canonical' => $canonical];
        if (!empty($opts['transactionHeaderId'])) {
            $payload['transaction_header_id'] = $opts['transactionHeaderId'];
        }
        return $payload;
    }

    /**
     * e-Arşiv (final consumer / non-registered recipient — TCKN is fine).
     *
     * The safe default for e-commerce: if the buyer turns out to be a registered
     * e-Fatura taxpayer, the server upgrades the document for you.
     */
    public static function earsiv(array $recipient, array $lines, array $opts = []): array
    {
        return self::build('EARSIV', $recipient, $lines, $opts);
    }

    /**
     * e-Fatura (GİB-registered recipient). `$recipientAlias` is optional — the
     * server resolves the mailbox handle from the VKN when you leave it empty.
     */
    public static function efatura(array $recipient, array $lines, string $recipientAlias = '', array $opts = []): array
    {
        return self::build('EFATURA', $recipient, $lines, ['recipientAlias' => $recipientAlias] + $opts);
    }

    /** @param array<string,mixed> $p */
    private static function party(array $p): array
    {
        return [
            'VKNorTCKN' => (string) ($p['vkn_tckn'] ?? ''),
            'Title' => $p['title'] ?? '',
            'Address' => $p['address'] ?? '',
            'TaxOffice' => $p['tax_office'] ?? '',
            'Email' => $p['email'] ?? '',
            'Phone' => $p['phone'] ?? '',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array{canon:array<int,array<string,mixed>>,totals:array<string,float>}
     */
    private static function linesAndTotals(array $lines): array
    {
        $canon = [];
        $subtotal = '0'; // cents
        $vatTotal = '0'; // cents
        foreach ($lines as $l) {
            $lineNet = self::mulCents($l['qty'], $l['unit_price']); // cents, scale 2
            // net (cents) * rate → cents; net is scale-2, so this stays exact
            $rate = self::toScaled($l['vat_rate']);
            $vat = self::roundTo2(bcmul($lineNet, $rate['digits'], 0), 2 + $rate['scale']);
            $subtotal = bcadd($subtotal, $lineNet, 0);
            $vatTotal = bcadd($vatTotal, $vat, 0);
            $canon[] = [
                'Title' => $l['title'],
                'ProductCode' => $l['product_code'] ?? '',
                'Quantity' => self::numify($l['qty']),
                'UnitPrice' => self::numify($l['unit_price']),
                'UnitCode' => $l['unit_code'] ?? 'C62',
                'VatRate' => self::numify($l['vat_rate']), // 0.20 == %20
                'LineTotal' => self::centsToNum($lineNet),
            ];
        }
        $totals = [
            'SubtotalExclVAT' => self::centsToNum($subtotal),
            'VatTotal' => self::centsToNum($vatTotal),
            'DiscountTotal' => 0,
            'GrandTotal' => self::centsToNum(bcadd($subtotal, $vatTotal, 0)),
        ];
        return ['canon' => $canon, 'totals' => $totals];
    }

    // --- exact decimal helpers (money must not drift; PHP float can't) --------

    /**
     * Parse a decimal-ish input into an integer-string of digits + its scale.
     * ponytail: plain decimal notation only; a value that stringifies to
     * scientific notation (1e-7) throws — pass it as a string if you need that.
     *
     * @param int|float|string $x
     * @return array{digits:string,scale:int}
     */
    private static function toScaled($x): array
    {
        $s = trim((string) $x);
        if (preg_match('/[eE]/', $s)) {
            throw new \InvalidArgumentException("pass very large/small numbers as strings, got: $s");
        }
        $neg = str_starts_with($s, '-');
        $body = ($neg || str_starts_with($s, '+')) ? substr($s, 1) : $s;
        [$int, $frac] = array_pad(explode('.', $body, 2), 2, '');
        $digits = ($int === '' ? '0' : $int) . $frac;
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            $digits = '0';
        }
        return ['digits' => ($neg ? '-' : '') . $digits, 'scale' => strlen($frac)];
    }

    /**
     * Round a scaled integer-string down to 2 decimals, banker's rounding
     * (round-half-even, matching Python's Decimal default). Returns cents.
     */
    private static function roundTo2(string $digits, int $scale): string
    {
        if ($scale <= 2) {
            return bcmul($digits, bcpow('10', (string) (2 - $scale)), 0);
        }
        $div = bcpow('10', (string) ($scale - 2));
        $neg = bccomp($digits, '0') < 0;
        $a = $neg ? ltrim($digits, '-') : $digits;
        $q = bcdiv($a, $div, 0);
        $r = bcmod($a, $div);
        $half = bcdiv($div, '2', 0); // div is a power of 10 ≥ 100 → exact half
        $cmp = bccomp($r, $half);
        if ($cmp > 0 || ($cmp === 0 && bcmod($q, '2') === '1')) {
            $q = bcadd($q, '1');
        }
        return $neg ? '-' . $q : $q;
    }

    /**
     * Multiply two decimal-ish inputs, return the product as integer cents (scale 2).
     *
     * @param int|float|string $a
     * @param int|float|string $b
     */
    private static function mulCents($a, $b): string
    {
        $x = self::toScaled($a);
        $y = self::toScaled($b);
        return self::roundTo2(bcmul($x['digits'], $y['digits'], 0), $x['scale'] + $y['scale']);
    }

    private static function centsToNum(string $cents): float
    {
        return (float) $cents / 100;
    }

    /**
     * Mirror JS `Number(x)`: keep whole values as int (so JSON renders `2`, not
     * `2.0`), fractional as float.
     *
     * @param int|float|string $v
     * @return int|float
     */
    private static function numify($v)
    {
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        $s = (string) $v;
        return (strpbrk($s, '.eE') === false) ? (int) $s : (float) $s;
    }
}
