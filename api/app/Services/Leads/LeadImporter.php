<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Imports leads from a CSV saved from the DigiFox lead sheet
 * (Google Sheets: File -> Download -> Comma-separated values).
 * Columns are matched by name, so their order does not matter.
 */
class LeadImporter
{
    /** Sheet column -> lead field. Keys are normalised (lowercase letters only). */
    private const COLUMNS = [
        'date' => 'date',
        'adname' => 'ad_name',
        'name' => 'full_name',
        'fullname' => 'full_name',
        'phonenumber' => 'phone',
        'phone' => 'phone',
        'emailid' => 'email',
        'email' => 'email',
        'locationcity' => 'city',
        'city' => 'city',
        'location' => 'city',
        'callstatus' => 'call_status',
        'status' => 'status',
        'package' => 'package',
        'requirement' => 'requirement',
        'followupstatus' => 'followup_status',
        'notesremarks' => 'notes',
        'notes' => 'notes',
        'remarks' => 'notes',
    ];

    public function __construct(private LeadOptions $options) {}

    /** @return array{created: int, duplicates: int, errors: list<string>} */
    public function import(string $path, ?User $by = null): array
    {
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh, escape: '\\');
        if (! $header) {
            return ['created' => 0, 'duplicates' => 0, 'errors' => ['The file is empty.']];
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]); // strip Excel BOM
        $map = [];
        foreach ($header as $i => $col) {
            $key = preg_replace('/[^a-z]/', '', strtolower((string) $col));
            if (isset(self::COLUMNS[$key])) {
                $map[$i] = self::COLUMNS[$key];
            }
        }
        if (! in_array('full_name', $map, true) || ! array_intersect(['phone', 'email'], $map)) {
            fclose($fh);

            return ['created' => 0, 'duplicates' => 0, 'errors' => ['Could not find the Name and Phone Number / Email ID columns. Please use the lead sheet headers.']];
        }

        $created = 0;
        $duplicates = 0;
        $errors = [];
        $line = 1;
        while (($row = fgetcsv($fh, escape: '\\')) !== false) {
            $line++;
            if (! array_filter($row, fn ($v) => trim((string) $v) !== '')) {
                continue;
            }
            $r = [];
            foreach ($map as $i => $field) {
                $r[$field] = trim((string) ($row[$i] ?? ''));
            }
            if (($r['full_name'] ?? '') === '' || (($r['phone'] ?? '') === '' && ($r['email'] ?? '') === '')) {
                $errors[] = "Row $line: name and a phone number or email are needed.";
                continue;
            }

            $phone = $this->phone($r['phone'] ?? '');
            $email = filter_var($r['email'] ?? '', FILTER_VALIDATE_EMAIL) ? strtolower($r['email']) : null;
            $dup = Lead::query()->where(fn ($q) => $q
                ->when($phone, fn ($q) => $q->orWhere('phone', $phone))
                ->when($email, fn ($q) => $q->orWhere('email', $email)))->exists();
            if ($dup) {
                $duplicates++;
                continue;
            }

            $date = $this->date($r['date'] ?? '');
            $statusKey = $this->options->statusKey($r['status'] ?? null);
            $notes = $r['notes'] ?? '';
            if (($r['status'] ?? '') !== '' && ! $statusKey) {
                $notes = trim("Status in sheet: {$r['status']}\n".$notes);
            }
            $callStatus = ($r['call_status'] ?? '') ?: null;
            $status = $statusKey ?? ($callStatus && strcasecmp($callStatus, 'Not called') !== 0 ? 'contacted' : 'new');

            $lead = Lead::create([
                'full_name' => Str::limit($r['full_name'], 250, ''),
                'phone' => $phone,
                'email' => $email,
                'city' => ($r['city'] ?? '') ?: null,
                'ad_name' => ($r['ad_name'] ?? '') ?: null,
                'call_status' => $callStatus ?? 'Not called',
                'status' => $status,
                'package' => ($r['package'] ?? '') ?: null,
                'requirement' => ($r['requirement'] ?? '') ?: null,
                'followup_status' => ($r['followup_status'] ?? '') ?: null,
                'next_followup_at' => $this->date($r['followup_status'] ?? ''),
                'notes' => $notes ?: null,
                'source' => 'import',
                // Old leads from the sheet were already handled, so they don't show as "uncontacted".
                'first_contacted_at' => $status !== 'new' ? ($date ?? now()) : null,
                'created_at' => $date ?? now(),
                'updated_at' => now(),
            ]);
            $lead->log($by, 'system', 'Imported from the lead sheet');
            $created++;
        }
        fclose($fh);

        return ['created' => $created, 'duplicates' => $duplicates, 'errors' => array_slice($errors, 0, 50)];
    }

    /** "+91 98470 12345", "9847012345", "919847012345" -> "+91 98470 12345" */
    public function phone(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) === 10) {
            $digits = '91'.$digits;
        }
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            return '+91 '.substr($digits, 2, 5).' '.substr($digits, 7);
        }

        return '+'.$digits;
    }

    /** Indian-style dates: 05/11/2026, 5-11-2026, 2026-11-05, 5 Nov 2026. */
    private function date(string $raw): ?CarbonImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('#(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})#', $raw, $m)) {
            $y = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
            if (checkdate((int) $m[2], (int) $m[1], $y)) {
                return CarbonImmutable::create($y, (int) $m[2], (int) $m[1], 10);
            }
        }
        if (preg_match('#\d{4}-\d{2}-\d{2}#', $raw, $m)) {
            return CarbonImmutable::parse($m[0])->setTime(10, 0);
        }
        if (preg_match('#\d{1,2}\s+[A-Za-z]{3,9}\s+\d{4}#', $raw, $m)) {
            try {
                return CarbonImmutable::parse($m[0])->setTime(10, 0);
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
