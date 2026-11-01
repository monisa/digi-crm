<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Realistic sample data for previewing the dashboard locally or on a staging
 * copy:  php artisan db:seed --class=DemoDataSeeder
 * Refuses to run in production.
 */
class DemoDataSeeder extends Seeder
{
    private const FIRST = ['Arjun', 'Priya', 'Rahul', 'Sneha', 'Vikram', 'Anjali', 'Rohan', 'Meera', 'Karthik', 'Divya', 'Nikhil', 'Aisha', 'Suresh', 'Lakshmi', 'Joseph', 'Fathima', 'Anand', 'Reshma', 'Vishnu', 'Neha'];
    private const LAST = ['Nair', 'Menon', 'Kumar', 'Pillai', 'Thomas', 'Varghese', 'Iyer', 'Reddy', 'Sharma', 'Joseph', 'Krishnan', 'Das', 'Rao', 'George'];
    private const BIZ = ['Jewellers', 'Organics', 'Realty', 'Clinic', 'Academy', 'Textiles', 'Bakers', 'Motors', 'Interiors', 'Travels', 'Dental Care', 'Builders', 'Spices', 'Fitness'];
    private const PREFIX = ['Sri Balaji', 'Green Leaf', 'Malabar', 'Kochi', 'Royal', 'Sunrise', 'Blue Ocean', 'Golden', 'Kerala', 'Lotus', 'Silver Oak', 'Coastal', 'Emerald', 'Pearl'];
    private const CAMPAIGNS = ['Diwali Offer', 'SEO Package', 'Website Dev', 'Onam Sale', 'Free Audit', 'Brand Launch'];
    private const EXPENSE_CATS = ['Ads spend' => 0.35, 'Salaries' => 0.45, 'Rent' => 0.08, 'Software' => 0.06, 'Travel' => 0.03, 'Misc' => 0.03];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data must not be loaded in production.');
        }

        mt_srand(2026);
        $now = CarbonImmutable::now();
        $rand = fn (array $a) => $a[mt_rand(0, count($a) - 1)];
        $person = fn () => $rand(self::FIRST).' '.$rand(self::LAST);

        $staff = User::where('role', 'employee')->pluck('id')->all();

        // Employees
        $cl = LeaveType::firstOrCreate(['code' => 'CL'], ['name' => 'Casual leave', 'yearly_quota' => 12]);
        $sl = LeaveType::firstOrCreate(['code' => 'SL'], ['name' => 'Sick leave', 'yearly_quota' => 6]);
        $roles = ['Digital Marketing Executive', 'SEO Analyst', 'Graphic Designer', 'Web Developer', 'Account Manager', 'Content Writer', 'Video Editor'];
        $employees = collect(range(1, 32))->map(fn ($i) => Employee::create([
            'emp_code' => sprintf('DFP%03d', $i),
            'name' => $person(),
            'designation' => $rand($roles),
            'department' => $rand(['Marketing', 'Design', 'Development', 'Accounts']),
            'doj' => $now->subDays(mt_rand(30, 1500)),
            'status' => $i <= 30 ? 'active' : 'inactive',
        ]));

        // Clients spread over the last 18 months, growing over time
        $clients = collect(range(1, 160))->map(function ($i) use ($now, $rand, $person) {
            $created = $now->subDays((int) (540 * (1 - sqrt($i / 160))));

            return Client::create([
                'company_name' => $rand(self::PREFIX).' '.$rand(self::BIZ),
                'contact_name' => $person(),
                'state' => mt_rand(1, 10) <= 8 ? 'Kerala' : $rand(['Karnataka', 'Tamil Nadu', 'Maharashtra']),
                'status' => mt_rand(1, 100) <= 92 ? 'active' : 'inactive',
                'created_at' => $created,
                'updated_at' => $created,
            ]);
        });

        // Monthly invoices and payments, plus expenses, for the last 13 months
        for ($m = 12; $m >= 0; $m--) {
            $month = $now->startOfMonth()->subMonths($m);
            $growth = 1 + (12 - $m) * 0.06;
            $active = $clients->filter(fn ($c) => $c->created_at <= $month->endOfMonth());
            foreach ($active->random(min($active->count(), (int) (28 * $growth))) as $client) {
                $amount = mt_rand(18, 95) * 1000;
                $issue = $month->addDays(mt_rand(0, 9));
                if ($issue > $now) {
                    continue;
                }
                $invoice = Invoice::create([
                    'client_id' => $client->id,
                    'invoice_no' => sprintf('DFP/%s/%04d', $issue->format('ym'), Invoice::count() + 1),
                    'issue_date' => $issue, 'due_date' => $issue->addDays(15),
                    'subtotal' => $amount, 'tax' => $amount * 0.18, 'total' => $amount * 1.18,
                    'amount_paid' => 0, 'status' => 'unpaid',
                ]);
                $paidOn = $issue->addDays(mt_rand(2, 20));
                if ($paidOn <= $now && mt_rand(1, 100) <= 90) {
                    Payment::create(['client_id' => $client->id, 'invoice_id' => $invoice->id, 'amount' => $invoice->total, 'paid_on' => $paidOn, 'mode' => 'bank_transfer']);
                    $invoice->update(['amount_paid' => $invoice->total, 'status' => 'paid']);
                }
            }

            $budget = 640000 * $growth * (mt_rand(92, 108) / 100);
            foreach (self::EXPENSE_CATS as $cat => $share) {
                $day = $month->addDays(mt_rand(0, 25));
                if ($day <= $now) {
                    Expense::create(['spent_on' => $day, 'category' => $cat, 'amount' => round($budget * $share, -2), 'status' => 'approved']);
                }
            }
        }

        // Leads: this month heavy, earlier months lighter
        $statuses = ['new' => 12, 'contacted' => 30, 'qualified' => 20, 'proposal' => 10, 'won' => 8, 'lost' => 20];
        for ($i = 0; $i < 900; $i++) {
            $created = $now->subMinutes(mt_rand(5, 60 * 24 * 120));
            $roll = mt_rand(1, 100);
            $status = 'new';
            foreach ($statuses as $s => $w) {
                if (($roll -= $w) <= 0) {
                    $status = $s;
                    break;
                }
            }
            Lead::create([
                'full_name' => $person(),
                'phone' => '+91 9'.mt_rand(100000000, 999999999),
                'source' => 'meta',
                'platform' => $rand(['facebook', 'instagram']),
                'campaign_name' => $rand(self::CAMPAIGNS),
                'ad_name' => $rand(self::CAMPAIGNS).' - '.$rand(['Video', 'Carousel', 'Image']),
                'city' => $rand(['Kochi', 'Thrissur', 'Kozhikode', 'Trivandrum', 'Kottayam', 'Kannur']),
                'call_status' => $status === 'new' ? 'Not called' : $rand(['Connected', 'Connected', 'Not answering', 'Busy', 'Call back later']),
                'package' => in_array($status, ['qualified', 'proposal', 'won'], true) ? $rand(['Starter', 'Growth', 'Premium']) : null,
                'requirement' => $rand(['SEO for website', 'Meta ads for new launch', 'New website', 'Social media management', 'Google ads']),
                'next_followup_at' => in_array($status, ['contacted', 'qualified', 'proposal'], true) && mt_rand(0, 1) ? $now->addDays(mt_rand(-3, 7))->setTime(11, 0) : null,
                'status' => $status,
                'assigned_to' => $staff && mt_rand(0, 4) ? $rand($staff) : null,
                'first_contacted_at' => $status === 'new' ? null : $created->addMinutes(mt_rand(5, 300)),
                'created_at' => $created, 'updated_at' => $created,
            ]);
        }

        // Tickets
        $subjects = ['Ad account disabled', 'Update landing page', 'Monthly report request', 'Change ad creative', 'Website form not working', 'Invoice query'];
        foreach (range(1, 40) as $i) {
            $created = $now->subDays(mt_rand(0, 60));
            Ticket::create([
                'client_id' => $clients->random()->id,
                'subject' => $rand($subjects),
                'priority' => $rand(['low', 'medium', 'medium', 'high']),
                'status' => $i <= 10 ? $rand(['open', 'in_progress', 'waiting']) : 'closed',
                'created_at' => $created, 'updated_at' => $created,
            ]);
        }

        // Leave requests
        foreach ($employees->where('status', 'active')->random(12) as $i => $employee) {
            $from = $now->addDays(mt_rand(-3, 20));
            $days = mt_rand(1, 3);
            LeaveRequest::create([
                'employee_id' => $employee->id,
                'leave_type_id' => mt_rand(0, 2) ? $cl->id : $sl->id,
                'from_date' => $from, 'to_date' => $from->addDays($days - 1), 'days' => $days,
                'reason' => 'Personal',
                'status' => $from < $now ? 'approved' : $rand(['pending', 'pending', 'approved']),
            ]);
        }
    }
}
