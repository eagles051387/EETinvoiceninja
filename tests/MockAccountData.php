<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2021. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://opensource.org/licenses/AAL
 */

namespace Tests;

use App\DataMapper\ClientSettings;
use App\DataMapper\CompanySettings;
use App\Factory\CompanyUserFactory;
use App\Factory\CreditFactory;
use App\Factory\InvoiceFactory;
use App\Factory\InvoiceInvitationFactory;
use App\Factory\InvoiceItemFactory;
use App\Factory\InvoiceToRecurringInvoiceFactory;
use App\Helpers\Invoice\InvoiceSum;
use App\Jobs\Company\CreateCompanyTaskStatuses;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\CompanyGateway;
use App\Models\CompanyToken;
use App\Models\CreditInvitation;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\GroupSetting;
use App\Models\InvoiceInvitation;
use App\Models\Product;
use App\Models\Project;
use App\Models\Quote;
use App\Models\QuoteInvitation;
use App\Models\RecurringInvoice;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContact;
use App\Utils\Traits\GeneratesCounter;
use App\Utils\Traits\MakesHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Class MockAccountData.
 */
trait MockAccountData
{
    use MakesHash;
    use GeneratesCounter;

    /**
     * @var
     */
    public $account;

    /**
     * @var
     */
    public $company;

    /**
     * @var
     */
    public $user;

    /**
     * @var
     */
    public $client;

    /**
     * @var
     */
    public $token;

    /**
     * @var
     */
    public $invoice;

    /**
     * @var
     */
    public $quote;

    /**
     * @var
     */
    public $vendor;

    /**
     * @var
     */
    public $expense;

    /**
     * @var
     */
    public $task;

    /**
     * @var
     */
    public $task_status;

    /**
     * @var
     */
    public $expense_category;

    /**
     * @var
     */
    public $cu;

    /**
     *
     */
    public function makeTestData()
    {
        config(['database.default' => config('ninja.db.default')]);

        /* Warm up the cache !*/
        $cached_tables = config('ninja.cached_tables');

        $this->artisan('db:seed --force');

        foreach ($cached_tables as $name => $class) {

            // check that the table exists in case the migration is pending
            if (! Schema::hasTable((new $class())->getTable())) {
                continue;
            }
            if ($name == 'payment_terms') {
                $orderBy = 'num_days';
            } elseif ($name == 'fonts') {
                $orderBy = 'sort_order';
            } elseif (in_array($name, ['currencies', 'industries', 'languages', 'countries', 'banks'])) {
                $orderBy = 'name';
            } else {
                $orderBy = 'id';
            }
            $tableData = $class::orderBy($orderBy)->get();
            if ($tableData->count()) {
                Cache::forever($name, $tableData);
            }
        }


        $this->account = Account::factory()->create();
        $this->company = Company::factory()->create([
                            'account_id' => $this->account->id,
                        ]);

        Storage::makeDirectory($this->company->company_key.'/documents', 0755, true);
        Storage::makeDirectory($this->company->company_key.'/images', 0755, true);

        $settings = CompanySettings::defaults();

        $settings->company_logo = asset('images/new_logo.png');
        $settings->website = 'www.invoiceninja.com';
        $settings->address1 = 'Address 1';
        $settings->address2 = 'Address 2';
        $settings->city = 'City';
        $settings->state = 'State';
        $settings->postal_code = 'Postal Code';
        $settings->phone = '555-343-2323';
        $settings->email = 'user@example.com';
        $settings->country_id = '840';
        $settings->vat_number = 'vat number';
        $settings->id_number = 'id number';
        $settings->use_credits_payment = 'always';
        $settings->timezone_id = '1';

        $this->company->settings = $settings;
        $this->company->save();

        $this->account->default_company_id = $this->company->id;
        $this->account->save();

        $user = User::whereEmail('user@example.com')->first();

        if (! $user) {
            $user = User::factory()->create([
                'account_id' => $this->account->id,
                'confirmation_code' => $this->createDbHash(config('database.default')),
                'email' => 'user@example.com',
            ]);
        }

        $user->password = Hash::make('ALongAndBriliantPassword');

        $user_id = $user->id;
        $this->user = $user;

        CreateCompanyTaskStatuses::dispatchNow($this->company, $this->user);

        $this->cu = CompanyUserFactory::create($user->id, $this->company->id, $this->account->id);
        $this->cu->is_owner = true;
        $this->cu->is_admin = true;
        $this->cu->save();

        $this->token = \Illuminate\Support\Str::random(64);

        $company_token = new CompanyToken;
        $company_token->user_id = $user->id;
        $company_token->company_id = $this->company->id;
        $company_token->account_id = $this->account->id;
        $company_token->name = 'test token';
        $company_token->token = $this->token;
        $company_token->is_system = true;

        $company_token->save();

        //todo create one token with token name TOKEN - use firstOrCreate

        Product::factory()->create([
                'user_id' => $user_id,
                'company_id' => $this->company->id,
        ]);

        $this->client = Client::factory()->create([
                'user_id' => $user_id,
                'company_id' => $this->company->id,
        ]);

        Storage::makeDirectory($this->company->company_key.'/'.$this->client->client_hash.'/invoices', 0755, true);
        Storage::makeDirectory($this->company->company_key.'/'.$this->client->client_hash.'/credits', 0755, true);
        Storage::makeDirectory($this->company->company_key.'/'.$this->client->client_hash.'/quotes', 0755, true);

        $contact = ClientContact::factory()->create([
                'user_id' => $user_id,
                'client_id' => $this->client->id,
                'company_id' => $this->company->id,
                'is_primary' => 1,
                'send_email' => true,
        ]);


        $contact2 = ClientContact::factory()->create([
                'user_id' => $user_id,
                'client_id' => $this->client->id,
                'company_id' => $this->company->id,
                'send_email' => true,
        ]);

        $this->vendor = Vendor::factory()->create([
            'user_id' => $user_id,
            'company_id' => $this->company->id,
        ]);


        $vendor_contact = VendorContact::factory()->create([
                'user_id' => $user_id,
                'vendor_id' => $this->vendor->id,
                'company_id' => $this->company->id,
                'is_primary' => 1,
                'send_email' => true,
        ]);

        $vendor_contact2 = VendorContact::factory()->create([
                'user_id' => $user_id,
                'vendor_id' => $this->vendor->id,
                'company_id' => $this->company->id,
                'send_email' => true,
        ]);

        $this->project = Project::factory()->create([
                'user_id' => $user_id,
                'company_id' => $this->company->id,
        ]);

        $this->expense = Expense::factory()->create([
            'user_id' => $user_id,
            'company_id' => $this->company->id,
        ]);

        $this->task = Task::factory()->create([
            'user_id' => $user_id,
            'company_id' => $this->company->id,
        ]);

        $this->task->status_id = TaskStatus::where('company_id', $this->company->id)->first()->id;
        $this->task->save();

        $this->expense_category = ExpenseCategory::factory()->create([
            'user_id' => $user_id,
            'company_id' => $this->company->id,
        ]);

        $this->task_status = TaskStatus::factory()->create([
            'user_id' => $user_id,
            'company_id' => $this->company->id,
        ]);

        $gs = new GroupSetting;
        $gs->name = 'Test';
        $gs->company_id = $this->client->company_id;
        $gs->settings = ClientSettings::buildClientSettings($this->company->settings, $this->client->settings);

        $gs_settings = $gs->settings;
        $gs_settings->website = 'http://staging.invoicing.co';
        $gs->settings = $gs_settings;
        $gs->save();

        $this->client->group_settings_id = $gs->id;
        $this->client->save();

        $this->invoice = InvoiceFactory::create($this->company->id, $user_id); //stub the company and user_id
        $this->invoice->client_id = $this->client->id;

        $this->invoice->line_items = $this->buildLineItems();
        $this->invoice->uses_inclusive_taxes = false;

        $this->invoice->save();

        $this->invoice_calc = new InvoiceSum($this->invoice);
        $this->invoice_calc->build();

        $this->invoice = $this->invoice_calc->getInvoice();

        $this->invoice->setRelation('client', $this->client);
        $this->invoice->setRelation('company', $this->company);

        $this->invoice->save();

        InvoiceInvitation::factory()->create([
                'user_id' => $this->invoice->user_id,
                'company_id' => $this->company->id,
                'client_contact_id' => $contact->id,
                'invoice_id' => $this->invoice->id,
            ]);

        InvoiceInvitation::factory()->create([
                'user_id' => $this->invoice->user_id,
                'company_id' => $this->company->id,
                'client_contact_id' => $contact2->id,
                'invoice_id' => $this->invoice->id,
            ]);

        $this->invoice->service()->markSent();

        $this->quote = Quote::factory()->create([
                'user_id' => $user_id,
                'client_id' => $this->client->id,
                'company_id' => $this->company->id,
            ]);

        $this->quote->line_items = $this->buildLineItems();
        $this->quote->uses_inclusive_taxes = false;

        $this->quote->save();

        $this->quote_calc = new InvoiceSum($this->quote);
        $this->quote_calc->build();

        $this->quote = $this->quote_calc->getQuote();

        $this->quote->status_id = Quote::STATUS_SENT;
        $this->quote->number = $this->getNextQuoteNumber($this->client);

        //$this->quote->service()->createInvitations()->markSent();

        QuoteInvitation::factory()->create([
                'user_id' => $user_id,
                'company_id' => $this->company->id,
                'client_contact_id' => $contact->id,
                'quote_id' => $this->quote->id,
            ]);

        QuoteInvitation::factory()->create([
                'user_id' => $user_id,
                'company_id' => $this->company->id,
                'client_contact_id' => $contact2->id,
                'quote_id' => $this->quote->id,
            ]);

        $this->quote->setRelation('client', $this->client);
        $this->quote->setRelation('company', $this->company);

        $this->quote->save();

        $this->credit = CreditFactory::create($this->company->id, $user_id);
        $this->credit->client_id = $this->client->id;

        $this->credit->line_items = $this->buildLineItems();
        $this->credit->amount = 10;
        $this->credit->balance = 10;

        $this->credit->tax_name1 = '';
        $this->credit->tax_name2 = '';
        $this->credit->tax_name3 = '';

        $this->credit->tax_rate1 = 0;
        $this->credit->tax_rate2 = 0;
        $this->credit->tax_rate3 = 0;

        $this->credit->uses_inclusive_taxes = false;
        $this->credit->save();


        $this->credit_calc = new InvoiceSum($this->credit);
        $this->credit_calc->build();

        $this->credit = $this->credit_calc->getCredit();

        $this->client->service()->adjustCreditBalance($this->credit->balance)->save();
        $this->credit->ledger()->updateCreditBalance($this->credit->balance)->save();
        $this->credit->number = $this->getNextCreditNumber($this->client);


        CreditInvitation::factory()->create([
                'user_id' => $user_id,
                'company_id' => $this->company->id,
                'client_contact_id' => $contact->id,
                'credit_id' => $this->credit->id,
            ]);

        CreditInvitation::factory()->create([
                'user_id' => $user_id,
                'company_id' => $this->company->id,
                'client_contact_id' => $contact2->id,
                'credit_id' => $this->credit->id,
            ]);

        $invitations = CreditInvitation::whereCompanyId($this->credit->company_id)
                                        ->whereCreditId($this->credit->id);

        $this->credit->setRelation('invitations', $invitations);

        $this->credit->service()->markSent();

        $this->credit->setRelation('client', $this->client);
        $this->credit->setRelation('company', $this->company);

        $this->credit->save();

        $contacts = $this->invoice->client->contacts;

        $contacts->each(function ($contact) {
            $invitation = InvoiceInvitation::whereCompanyId($this->invoice->company_id)
                                        ->whereClientContactId($contact->id)
                                        ->whereInvoiceId($this->invoice->id)
                                        ->first();

            if (! $invitation && $contact->send_email) {
                $ii = InvoiceInvitationFactory::create($this->invoice->company_id, $this->invoice->user_id);
                $ii->invoice_id = $this->invoice->id;
                $ii->client_contact_id = $contact->id;
                $ii->save();
            } elseif ($invitation && ! $contact->send_email) {
                $invitation->delete();
            }
        });

        $invitations = InvoiceInvitation::whereCompanyId($this->invoice->company_id)
                                        ->whereInvoiceId($this->invoice->id);

        $this->invoice->setRelation('invitations', $invitations);

        $this->invoice->save();

        $this->invoice->ledger()->updateInvoiceBalance($this->invoice->amount);
        // UpdateCompanyLedgerWithInvoice::dispatchNow($this->invoice, $this->invoice->amount, $this->invoice->company);

        $user_id = $this->invoice->user_id;

        $recurring_invoice = InvoiceToRecurringInvoiceFactory::create($this->invoice);
        $recurring_invoice->user_id = $user_id;
        $recurring_invoice->next_send_date = Carbon::now();
        $recurring_invoice->status_id = RecurringInvoice::STATUS_ACTIVE;
        $recurring_invoice->remaining_cycles = 2;
        $recurring_invoice->next_send_date = Carbon::now();
        $recurring_invoice->save();

        $recurring_invoice->number = $this->getNextInvoiceNumber($this->invoice->client, $this->invoice);
        $recurring_invoice->save();

        $recurring_invoice = InvoiceToRecurringInvoiceFactory::create($this->invoice);
        $recurring_invoice->user_id = $user_id;
        $recurring_invoice->next_send_date = Carbon::now()->addMinutes(2);
        $recurring_invoice->status_id = RecurringInvoice::STATUS_ACTIVE;
        $recurring_invoice->remaining_cycles = 2;
        $recurring_invoice->next_send_date = Carbon::now();
        $recurring_invoice->save();

        $recurring_invoice->number = $this->getNextInvoiceNumber($this->invoice->client, $this->invoice);
        $recurring_invoice->save();

        $recurring_invoice = InvoiceToRecurringInvoiceFactory::create($this->invoice);
        $recurring_invoice->user_id = $user_id;
        $recurring_invoice->next_send_date = Carbon::now()->addMinutes(10);
        $recurring_invoice->status_id = RecurringInvoice::STATUS_ACTIVE;
        $recurring_invoice->remaining_cycles = 2;
        $recurring_invoice->next_send_date = Carbon::now();
        $recurring_invoice->save();

        $recurring_invoice->number = $this->getNextInvoiceNumber($this->invoice->client, $this->invoice);
        $recurring_invoice->save();

        $recurring_invoice = InvoiceToRecurringInvoiceFactory::create($this->invoice);
        $recurring_invoice->user_id = $user_id;
        $recurring_invoice->next_send_date = Carbon::now()->addMinutes(15);
        $recurring_invoice->status_id = RecurringInvoice::STATUS_ACTIVE;
        $recurring_invoice->remaining_cycles = 2;
        $recurring_invoice->next_send_date = Carbon::now();
        $recurring_invoice->save();

        $recurring_invoice->number = $this->getNextInvoiceNumber($this->invoice->client, $this->invoice);
        $recurring_invoice->save();

        $recurring_invoice = InvoiceToRecurringInvoiceFactory::create($this->invoice);
        $recurring_invoice->user_id = $user_id;
        $recurring_invoice->next_send_date = Carbon::now()->addMinutes(20);
        $recurring_invoice->status_id = RecurringInvoice::STATUS_ACTIVE;
        $recurring_invoice->remaining_cycles = 2;
        $recurring_invoice->next_send_date = Carbon::now();
        $recurring_invoice->save();

        $recurring_invoice->number = $this->getNextInvoiceNumber($this->invoice->client, $this->invoice);
        $recurring_invoice->save();

        $recurring_invoice = InvoiceToRecurringInvoiceFactory::create($this->invoice);
        $recurring_invoice->user_id = $user_id;
        $recurring_invoice->next_send_date = Carbon::now()->addDays(10);
        $recurring_invoice->status_id = RecurringInvoice::STATUS_ACTIVE;
        $recurring_invoice->remaining_cycles = 2;
        $recurring_invoice->next_send_date = Carbon::now()->addDays(10);
        $recurring_invoice->save();

        $recurring_invoice->number = $this->getNextInvoiceNumber($this->invoice->client, $this->invoice);
        $recurring_invoice->save();

        $gs = new GroupSetting;
        $gs->company_id = $this->company->id;
        $gs->user_id = $user_id;
        $gs->settings = ClientSettings::buildClientSettings(CompanySettings::defaults(), ClientSettings::defaults());
        $gs->name = 'Default Client Settings';
        $gs->save();

        if (config('ninja.testvars.stripe')) {
            $data = [];
            $data[1]['min_limit'] = 234;
            $data[1]['max_limit'] = 65317;
            $data[1]['fee_amount'] = 0.00;
            $data[1]['fee_percent'] = 0.000;
            $data[1]['fee_tax_name1'] = '';
            $data[1]['fee_tax_rate1'] = '';
            $data[1]['fee_tax_name2'] = '';
            $data[1]['fee_tax_rate2'] = '';
            $data[1]['fee_tax_name3'] = '';
            $data[1]['fee_tax_rate3'] = 0;
            $data[1]['fee_cap'] = '';
            $data[1]['is_enabled'] = true;

            $cg = new CompanyGateway;
            $cg->company_id = $this->company->id;
            $cg->user_id = $user_id;
            $cg->gateway_key = 'd14dd26a37cecc30fdd65700bfb55b23';
            $cg->require_cvv = true;
            $cg->require_billing_address = true;
            $cg->require_shipping_address = true;
            $cg->update_details = true;
            $cg->config = encrypt(config('ninja.testvars.stripe'));
            $cg->fees_and_limits = $data;
            $cg->save();

            $cg = new CompanyGateway;
            $cg->company_id = $this->company->id;
            $cg->user_id = $user_id;
            $cg->gateway_key = 'd14dd26a37cecc30fdd65700bfb55b23';
            $cg->require_cvv = true;
            $cg->require_billing_address = true;
            $cg->require_shipping_address = true;
            $cg->update_details = true;
            $cg->fees_and_limits = $data;
            $cg->config = encrypt(config('ninja.testvars.stripe'));
            $cg->save();
        }
    }

    /**
     * @return array
     */
    private function buildLineItems()
    {
        $line_items = [];

        $item = InvoiceItemFactory::create();
        $item->quantity = 1;
        $item->cost = 10;
        $item->task_id = $this->encodePrimaryKey($this->task->id);
        $item->expense_id = $this->encodePrimaryKey($this->expense->id);

        $line_items[] = $item;

        return $line_items;
    }
}
