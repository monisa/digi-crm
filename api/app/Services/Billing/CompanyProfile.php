<?php

namespace App\Services\Billing;

use App\Models\Setting;

/**
 * Company details for invoices (entered in Settings → Company, stored in the
 * database, never in the code repository).
 */
class CompanyProfile
{
    public const DEFAULTS = [
        'legal_name' => 'DIGIFOX PRO SOLUTIONS LLP',
        'address' => '',
        'city' => 'Kochi',
        'state' => 'Kerala',
        'pincode' => '',
        'gstin' => '',
        'pan' => '',
        'email' => 'info@digifoxprosolutions.com',
        'phone' => '',
        'website' => 'https://digifoxprosolutions.com',
        'upi_id' => '',
        'upi_name' => 'DIGIFOX PRO SOLUTIONS LLP',
        'bank_account_name' => '',
        'bank_account_number' => '',
        'bank_ifsc' => '',
        'bank_name' => '',
        'bank_branch' => '',
        'swift' => '',
        'invoice_prefix' => 'DFP',
        'default_sac' => '998361',
        'default_gst_rate' => 18,
        'payment_terms_days' => 15,
        'terms' => "Payment due within 15 days of the invoice date.\nPlease quote the invoice number with your payment.",
        'lut_number' => '',
    ];

    public function get(): array
    {
        return [...self::DEFAULTS, ...(Setting::get('company', []) ?? [])];
    }

    public function save(array $data): array
    {
        Setting::put('company', array_intersect_key([...$this->get(), ...$data], self::DEFAULTS));

        return $this->get();
    }

    /** First two digits of the GSTIN, or Kerala (32) if not set yet. */
    public function stateCode(): string
    {
        $gstin = $this->get()['gstin'];

        return preg_match('/^\d{2}/', $gstin) ? substr($gstin, 0, 2) : '32';
    }
}
