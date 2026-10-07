<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace Tests\Feature;

use App\Models\Quote;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MockAccountData;
use Tests\TestCase;

class ChartCalculatedFieldsActiveQuotesTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00 UTC'));
        $this->makeTestData();
        $this->quote->status_id = Quote::STATUS_DRAFT;
        $this->quote->save();
        // Use the company workflow setting instead of MockAccountData's group override.
        $this->client->group_settings_id = null;
        $this->client->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public static function archiveCases(): array
    {
        return [
            'sent future' => [Quote::STATUS_SENT, '2026-10-06', 1],
            'approved future' => [Quote::STATUS_APPROVED, '2026-10-06', 1],
            'sent today' => [Quote::STATUS_SENT, '2026-10-05', 1],
            'sent no expiry' => [Quote::STATUS_SENT, null, 1],
            'sent expired' => [Quote::STATUS_SENT, '2026-10-04', 0],
            'approved expired' => [Quote::STATUS_APPROVED, '2026-10-04', 0],
        ];
    }

    #[DataProvider('archiveCases')]
    public function testArchivedQuotesAreExcludedFromActiveMetrics(int $status, ?string $dueDate, int $active): void
    {
        $quote = $this->createMetricQuote(['status_id' => $status, 'due_date' => $dueDate]);

        $this->assertMetrics($active, $active * 120, $active * 120);

        $this->quoteAction($quote, 'archive')->assertOk();
        $archived = Quote::withTrashed()->findOrFail($quote->id);
        $this->assertTrue($archived->trashed());
        $this->assertFalse((bool) $archived->is_deleted);
        $this->assertMetrics(0, 0, 0);

        $this->quoteAction($archived, 'restore')->assertOk();
        $this->assertMetrics($active, $active * 120, $active * 120);
    }

    public static function conversionCases(): array
    {
        return [
            'auto archive on future' => [true, '2026-10-06'],
            'auto archive off future' => [false, '2026-10-06'],
            'auto archive on today' => [true, '2026-10-05'],
            'auto archive off today' => [false, '2026-10-05'],
        ];
    }

    #[DataProvider('conversionCases')]
    public function testConvertedQuotesAreExcludedWithEitherAutoArchiveSetting(bool $autoArchive, string $dueDate): void
    {
        $settings = $this->company->settings;
        $settings->auto_archive_quote = $autoArchive;
        $this->company->settings = $settings;
        $this->company->save();
        $this->assertSame($autoArchive, $this->client->fresh()->getSetting('auto_archive_quote'));

        $quote = $this->createMetricQuote(['due_date' => $dueDate]);
        $this->assertMetrics(1, 120, 120);

        $this->quoteAction($quote, 'convert_to_invoice')->assertOk();

        $converted = Quote::withTrashed()->findOrFail($quote->id);
        $this->assertNotNull($converted->invoice_id);
        $this->assertSame(Quote::STATUS_CONVERTED, $converted->status_id);
        $this->assertSame($autoArchive, $converted->trashed());
        $this->assertMetrics(0, 0, 0);
    }

    public function testActiveQuotesKeepDateAndStatusFilters(): void
    {
        $this->createMetricQuote(['amount' => 120]);
        $this->createMetricQuote(['status_id' => Quote::STATUS_APPROVED, 'due_date' => null, 'amount' => 240]);
        $this->createMetricQuote(['date' => '2026-09-15', 'amount' => 360]);
        $this->createMetricQuote(['due_date' => '2026-10-04', 'amount' => 1000]);
        $this->createMetricQuote(['status_id' => Quote::STATUS_DRAFT, 'amount' => 1000]);
        $this->createMetricQuote(['status_id' => Quote::STATUS_CONVERTED, 'invoice_id' => $this->invoice->id, 'amount' => 1000]);
        $this->createMetricQuote(['status_id' => Quote::STATUS_CANCELLED, 'amount' => 1000]);
        $this->createMetricQuote(['status_id' => Quote::STATUS_REJECTED, 'amount' => 1000]);
        $this->createMetricQuote(['is_deleted' => true, 'amount' => 1000]);
        $archived = $this->createMetricQuote(['amount' => 1000]);
        $this->quoteAction($archived, 'archive')->assertOk();

        $this->assertMetrics(3, 720, 240);
        $this->assertMetrics(2, 360, 180, 'current');
        $this->assertMetrics(1, 360, 360, 'previous');
    }

    private function createMetricQuote(array $attributes): Quote
    {
        return Quote::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'status_id' => Quote::STATUS_SENT,
            'number' => 'active-quote-' . uniqid(),
            'date' => '2026-10-01',
            'due_date' => '2026-10-06',
            'amount' => 120,
            'is_deleted' => false,
        ], $attributes));
    }

    private function quoteAction(Quote $quote, string $action): TestResponse
    {
        return $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson('/api/v1/quotes/bulk', [
            'action' => $action,
            'ids' => [$quote->hashed_id],
        ]);
    }

    private function assertMetrics(int $count, int $sum, int $average, string $period = 'total'): void
    {
        foreach (['count' => $count, 'sum' => $sum, 'avg' => $average] as $calculation => $expected) {
            $response = $this->withHeaders([
                'X-API-SECRET' => config('ninja.api_secret'),
                'X-API-TOKEN' => $this->token,
            ])->postJson('/api/v1/charts/calculated_fields', [
                'field' => 'active_quotes',
                'date_range' => 'this_month',
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-31',
                'calculation' => $calculation,
                'period' => $period,
                'format' => 'money',
            ])->assertOk();

            $this->assertEquals($expected, $response->json(), $period . ' ' . $calculation);
        }
    }
}
