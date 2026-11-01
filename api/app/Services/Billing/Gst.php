<?php

namespace App\Services\Billing;

/** Indian GST: same state → CGST + SGST, other state → IGST, outside India → export (zero-rated). */
class Gst
{
    /** GST state codes (first two digits of a GSTIN). */
    public const STATES = [
        '01' => 'Jammu and Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh', '05' => 'Uttarakhand',
        '06' => 'Haryana', '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh', '10' => 'Bihar', '11' => 'Sikkim',
        '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur', '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya',
        '18' => 'Assam', '19' => 'West Bengal', '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh',
        '24' => 'Gujarat', '26' => 'Dadra and Nagar Haveli and Daman and Diu', '27' => 'Maharashtra', '29' => 'Karnataka', '30' => 'Goa',
        '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu', '34' => 'Puducherry', '35' => 'Andaman and Nicobar Islands',
        '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh',
    ];

    public function __construct(private CompanyProfile $company) {}

    /** "Kerala" → "32" (PHP turns numeric array keys into ints, so pad them back). */
    public static function codeFor(?string $state): ?string
    {
        $code = $state ? array_search($state, self::STATES, true) : false;

        return $code === false ? null : str_pad((string) $code, 2, '0', STR_PAD_LEFT);
    }

    /** Work out GST type from the client's GSTIN (preferred) or state. */
    public function typeFor(?string $clientGstin, ?string $clientState): string
    {
        if ($clientState === 'Outside India') {
            return 'export';
        }
        $ours = $this->company->stateCode();
        $theirs = $clientGstin && preg_match('/^\d{2}/', $clientGstin) ? substr($clientGstin, 0, 2) : self::codeFor($clientState);

        return $theirs === null || $theirs === $ours ? 'intra' : 'inter';
    }

    /** @return array{subtotal: float, cgst: float, sgst: float, igst: float, tax: float, total: float} */
    public function totals(float $subtotal, string $type, float $rate): array
    {
        $subtotal = round($subtotal, 2);
        $cgst = $sgst = $igst = 0.0;
        if ($type === 'intra') {
            $cgst = $sgst = round($subtotal * $rate / 200, 2);
        } elseif ($type === 'inter') {
            $igst = round($subtotal * $rate / 100, 2);
        }
        $tax = round($cgst + $sgst + $igst, 2);

        return ['subtotal' => $subtotal, 'cgst' => $cgst, 'sgst' => $sgst, 'igst' => $igst, 'tax' => $tax, 'total' => round($subtotal + $tax, 2)];
    }
}
