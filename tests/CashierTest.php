<?php

namespace Laravel\Cashier\Tests;

use Carbon\Carbon;
use Illuminate\Http\Request;
use PHPUnit_Framework_TestCase;
use Laravel\Cashier\Tests\Fixtures\User;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Laravel\Cashier\Tests\Fixtures\CashierTestControllerStub;

class CashierTest extends PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
        if (file_exists(__DIR__.'/../.env')) {
            $dotenv = new \Dotenv\Dotenv(__DIR__.'/../');
            $dotenv->load();
        }
    }

    public function setUp()
    {
        Eloquent::unguard();

        $db = new DB;
        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $db->bootEloquent();
        $db->setAsGlobal();

        $this->schema()->create('Users', function ($table) {
            $table->increments('UsersId');
            $table->string('UsersEmail');
            $table->string('UsersName');
            $table->string('UsersStripeId')->nullable();
            $table->string('UsersCardBrand')->nullable();
            $table->string('UsersCardLastFour')->nullable();
            $table->timestamp('UsersCreatedAt');
            $table->timestamp('UsersUpdatedAt');
            //$table->timestamps();
        });

        $this->schema()->create('Subscriptions', function ($table) {
            $table->increments('SubscriptionsId');
            $table->integer('SubscriptionsUserId');
            $table->string('SubscriptionsName');
            $table->string('SubscriptionsStripeId');
            $table->string('SubscriptionsStripePlan');
            $table->integer('SubscriptionsQuantity');
            $table->timestamp('SubscriptionsTrialEndsAt')->nullable();
            $table->timestamp('SubscriptionsEndsAt')->nullable();
            $table->timestamp('SubscriptionsCreatedAt');
            $table->timestamp('SubscriptionsUpdatedAt');
            //$table->timestamps();
        });
    }

    public function tearDown()
    {
        $this->schema()->drop('Users');
        $this->schema()->drop('Subscriptions');
    }

    /**
     * Tests.
     */
    public function testSubscriptionsCanBeCreated()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')->create($this->getTestToken());

        $this->assertEquals(1, count($user->subscriptions));
        $this->assertNotNull($user->subscription('main')->UsersStripeId);

        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribedToPlan('monthly-10-1', 'main'));
        $this->assertFalse($user->subscribedToPlan('monthly-10-1', 'something'));
        $this->assertFalse($user->subscribedToPlan('monthly-10-2', 'main'));
        $this->assertTrue($user->subscribed('main', 'monthly-10-1'));
        $this->assertFalse($user->subscribed('main', 'monthly-10-2'));
        $this->assertTrue($user->subscription('main')->active());
        $this->assertFalse($user->subscription('main')->cancelled());
        $this->assertFalse($user->subscription('main')->onGracePeriod());

        // Cancel Subscription
        $subscription = $user->subscription('main');
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->cancelled());
        $this->assertTrue($subscription->onGracePeriod());

        // Modify Ends Date To Past
        $oldGracePeriod = $subscription->SubscriptionsEndsAt;
        $subscription->fill(['SubscriptionsEndsAt' => Carbon::now()->subDays(5)])->save();

        $this->assertFalse($subscription->active());
        $this->assertTrue($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());

        $subscription->fill(['SubscriptionsEndsAt' => $oldGracePeriod])->save();

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());

        // Increment & Decrement
        $subscription->incrementQuantity();

        $this->assertEquals(2, $subscription->SubscriptionsQuantity);

        $subscription->decrementQuantity();

        $this->assertEquals(1, $subscription->SubscriptionsQuantity);

        // Swap Plan
        $subscription->swap('monthly-10-2');

        $this->assertEquals('monthly-10-2', $subscription->SubscriptionsStripePlan);

        // Invoice Tests
        $invoice = $user->invoices()[1];

        $this->assertEquals('$10.00', $invoice->total());
        $this->assertFalse($invoice->hasDiscount());
        $this->assertFalse($invoice->hasStartingBalance());
        $this->assertNull($invoice->coupon());
        $this->assertInstanceOf(Carbon::class, $invoice->date());
    }

    public function test_creating_subscription_with_coupons()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
                ->withCoupon('coupon-1')->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribed('main', 'monthly-10-1'));
        $this->assertFalse($user->subscribed('main', 'monthly-10-2'));
        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());

        // Invoice Tests
        $invoice = $user->invoices()[0];

        $this->assertTrue($invoice->hasDiscount());
        $this->assertEquals('$5.00', $invoice->total());
        $this->assertEquals('$5.00', $invoice->amountOff());
        $this->assertFalse($invoice->discountIsPercentage());
    }

    public function test_generic_trials()
    {
        $user = new User;
        $this->assertFalse($user->onGenericTrial());
        $user->trial_ends_at = Carbon::tomorrow();
        $this->assertTrue($user->onGenericTrial());
        $user->trial_ends_at = Carbon::today()->subDays(5);
        $this->assertFalse($user->onGenericTrial());
    }

    public function test_creating_subscription_with_trial()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
                ->trialDays(7)->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onTrial());
        $this->assertEquals(Carbon::today()->addDays(7)->day, $subscription->SubscriptionsTrialEndsAt->day);

        // Cancel Subscription
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onGracePeriod());

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertTrue($subscription->onTrial());
        $this->assertEquals(Carbon::today()->addDays(7)->day, $subscription->SubscriptionsTrialEndsAt->day);
    }

    public function test_creating_subscription_with_explicit_trial()
    {
        $user = User::create([
             'email' => 'taylor@laravel.com',
             'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
             ->trialUntil(Carbon::tomorrow()->hour(3)->minute(15))->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onTrial());
        $this->assertEquals(Carbon::tomorrow()->hour(3)->minute(15), $subscription->SubscriptionsTrialEndsAt);

        // Cancel Subscription
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onGracePeriod());

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertTrue($subscription->onTrial());
        $this->assertEquals(Carbon::tomorrow()->hour(3)->minute(15), $subscription->SubscriptionsTrialEndsAt);
    }

    public function test_applying_coupons_to_existing_customers()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
                ->create($this->getTestToken());

        $user->applyCoupon('coupon-1');

        $customer = $user->asStripeCustomer();

        $this->assertEquals('coupon-1', $customer->discount->coupon->id);
    }

    /**
     * @group foo
     */
    public function test_marking_as_cancelled_from_webhook()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        $user->newSubscription('main', 'monthly-10-1')
                ->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $request = Request::create('/', 'POST', [], [], [], [], json_encode(['id' => 'foo', 'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => $subscription->SubscriptionsStripeId,
                    'customer' => $user->UsersStripeId,
                ],
            ],
        ]));

        $controller = new CashierTestControllerStub;
        $response = $controller->handleWebhook($request);
        $this->assertEquals(200, $response->getStatusCode());

        $user = $user->fresh();
        $subscription = $user->subscription('main');

        $this->assertTrue($subscription->cancelled());
    }

    public function testCreatingOneOffInvoices()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Invoice
        $user->createAsStripeCustomer($this->getTestToken());
        $user->invoiceFor('Laravel Cashier', 1000);

        // Invoice Tests
        $invoice = $user->invoices()[0];
        $this->assertEquals('$10.00', $invoice->total());
        $this->assertEquals('Laravel Cashier', $invoice->invoiceItems()[0]->asStripeInvoiceItem()->description);
    }

    public function testRefunds()
    {
        $user = User::create([
            'email' => 'taylor@laravel.com',
            'name' => 'Taylor Otwell',
        ]);

        // Create Invoice
        $user->createAsStripeCustomer($this->getTestToken());
        $invoice = $user->invoiceFor('Laravel Cashier', 1000);

        // Create the refund
        $refund = $user->refund($invoice->charge);

        // Refund Tests
        $this->assertEquals(1000, $refund->amount);
    }

    protected function getTestToken()
    {
        return \Stripe\Token::create([
            'card' => [
                'number' => '4242424242424242',
                'exp_month' => 5,
                'exp_year' => 2020,
                'cvc' => '123',
            ],
        ], ['api_key' => getenv('STRIPE_SECRET')])->id;
    }

    /**
     * Schema Helpers.
     */
    protected function schema()
    {
        return $this->connection()->getSchemaBuilder();
    }

    protected function connection()
    {
        return Eloquent::getConnectionResolver()->connection();
    }
}
