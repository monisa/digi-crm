<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\CompanyProfile;
use App\Services\Billing\Gst;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    public function show(CompanyProfile $company): JsonResponse
    {
        return response()->json(['company' => $company->get(), 'states' => array_values(Gst::STATES)]);
    }

    public function update(Request $request, CompanyProfile $company): JsonResponse
    {
        $s = ['nullable', 'string', 'max:190'];
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => $s, 'pincode' => ['nullable', 'regex:/^\d{6}$/'],
            'state' => ['required', Rule::in(array_values(Gst::STATES))],
            'gstin' => ['nullable', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/'],
            'pan' => ['nullable', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'email' => ['nullable', 'email'], 'phone' => $s, 'website' => $s,
            'upi_id' => ['nullable', 'regex:/^[a-zA-Z0-9.\-_]{2,256}@[a-zA-Z]{2,64}$/'],
            'upi_name' => $s,
            'bank_account_name' => $s, 'bank_account_number' => ['nullable', 'regex:/^\d{6,20}$/'],
            'bank_ifsc' => ['nullable', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'], 'bank_name' => $s, 'bank_branch' => $s,
            'swift' => ['nullable', 'regex:/^[A-Z0-9]{8}([A-Z0-9]{3})?$/'],
            'invoice_prefix' => ['required', 'regex:/^[A-Z0-9]{1,8}$/'],
            'default_sac' => ['nullable', 'regex:/^\d{4,8}$/'],
            'default_gst_rate' => ['required', 'numeric', Rule::in([0, 5, 12, 18, 28])],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:120'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'lut_number' => $s,
        ], [
            'gstin.regex' => 'Enter a valid 15-character GSTIN.',
            'upi_id.regex' => 'Enter a UPI ID like yourname@okbank.',
            'bank_ifsc.regex' => 'Enter an 11-character IFSC like ABCD0123456 (no spaces).',
            'swift.regex' => 'Enter a SWIFT code like ABCDINBBXXX (no spaces).',
            'pan.regex' => 'Enter a valid 10-character PAN, e.g. ABCDE1234F.',
            'invoice_prefix.regex' => 'Use up to 8 capital letters or digits, e.g. DFP.',
        ]);

        return response()->json(['company' => $company->save(array_map(fn ($v) => $v ?? '', $data)), 'states' => array_values(Gst::STATES)]);
    }
}
