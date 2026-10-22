<?php

declare(strict_types=1);

namespace Aslan\Payment\Resources;

use Aslan\Payment\Aslan;
use Aslan\Payment\Dto\PaginatedResponse;
use Aslan\Payment\Dto\Subscription;
use Aslan\Payment\Dto\SubscriptionCheckout;
use Aslan\Payment\Dto\SubscriptionPaymentLink;
use Aslan\Payment\Dto\SubscriptionPlanChange;
use Aslan\Payment\Dto\SubscriptionPriceOverride;
use Aslan\Payment\Dto\SubscriptionStatusChange;

/**
 * Subscriptions resource.
 *
 * Lifecycle: create() returns a pending_initial subscription plus the hosted
 * Paywall the customer must complete to save their card. Afterwards the
 * subscription renews automatically; follow it through `subscription.*`
 * webhooks.
 *
 * @see https://docs.aslanpay.ma/api/billing
 */
final class Subscriptions
{
    public function __construct(private readonly Aslan $client) {}

    /**
     * Create a subscription (INITIAL flow).
     *
     * Required params:
     *  - planId (string, uuid)
     *  - customerId (string, uuid)
     *  - returnUrl, cancelUrl, callbackUrl (string, uri)
     *
     * Optional params:
     *  - priceOverride (int, centimes, 1 <= value <= plan amount)
     *  - priceOverrideMonths (int, >= 1) — time-boxes priceOverride
     *  - metadata (array<string, string>) — keys starting with "__" are dropped
     *
     * No-trial plans charge the first period on the Paywall; trial plans only
     * charge an auto-refunded card-verification amount.
     *
     * @param  array<string, mixed> $params
     */
    public function create(array $params): SubscriptionCheckout
    {
        $response = $this->client->request('POST', '/api/v1/billing/subscriptions', $params);

        return SubscriptionCheckout::fromArray($response['data'] ?? $response);
    }

    /**
     * List subscriptions.
     *
     * Query params:
     *  - page (int, default 1)
     *  - pageSize (int, default 20, max 100)
     *  - status (string) — pending_initial | trialing | active | past_due | paused | cancelled
     *  - customerId (string, uuid)
     *  - planId (string, uuid)
     *
     * @param  array<string, mixed> $query
     * @return PaginatedResponse<Subscription>
     */
    public function list(array $query = []): PaginatedResponse
    {
        $response = $this->client->request('GET', '/api/v1/billing/subscriptions', [], [], $query);

        return PaginatedResponse::fromArray(
            $response,
            static fn (array $item) => Subscription::fromArray($item),
        );
    }

    /**
     * Retrieve a subscription, with its plan, customer and card on file.
     */
    public function retrieve(string $id): Subscription
    {
        $response = $this->client->request('GET', $this->path($id));

        return Subscription::fromArray($response['data'] ?? $response);
    }

    /**
     * Set, replace or clear the per-subscription price override. Takes effect
     * at the next renewal (no charge now). Blocked on cancelled subscriptions.
     *
     * Params:
     *  - priceOverride (int|null, centimes) — null clears the override
     *  - priceOverrideMonths (int|null) — duration from now; omit for permanent
     *
     * @param  array<string, mixed> $params
     */
    public function update(string $id, array $params): SubscriptionPriceOverride
    {
        $response = $this->client->request('PATCH', $this->path($id), $params);

        return SubscriptionPriceOverride::fromArray($response['data'] ?? $response);
    }

    /**
     * Move the subscription to another plan, reusing the card on file.
     *
     * @param  string $when            immediate | period_end | immediate_proration
     * @param  bool   $allowDowngrade  Opt in to moving to a cheaper plan (403 otherwise)
     */
    public function changePlan(
        string $id,
        string $planId,
        string $when = 'immediate',
        bool $allowDowngrade = false,
    ): SubscriptionPlanChange {
        $response = $this->client->request('POST', $this->path($id, 'change-plan'), [
            'planId'         => $planId,
            'when'           => $when,
            'allowDowngrade' => $allowDowngrade,
        ]);

        return SubscriptionPlanChange::fromArray($response['data'] ?? $response);
    }

    /**
     * Cancel a subscription — immediately by default, or at the end of the
     * current period when $atPeriodEnd is true (reversible with uncancel()).
     */
    public function cancel(string $id, bool $atPeriodEnd = false): SubscriptionStatusChange
    {
        $response = $this->client->request('POST', $this->path($id, 'cancel'), ['atPeriodEnd' => $atPeriodEnd]);

        return SubscriptionStatusChange::fromArray($response['data'] ?? $response);
    }

    /**
     * Reverse a scheduled period-end cancellation.
     */
    public function uncancel(string $id): SubscriptionStatusChange
    {
        $response = $this->client->request('POST', $this->path($id, 'uncancel'));

        return SubscriptionStatusChange::fromArray($response['data'] ?? $response);
    }

    public function pause(string $id): SubscriptionStatusChange
    {
        $response = $this->client->request('POST', $this->path($id, 'pause'));

        return SubscriptionStatusChange::fromArray($response['data'] ?? $response);
    }

    public function resume(string $id): SubscriptionStatusChange
    {
        $response = $this->client->request('POST', $this->path($id, 'resume'));

        return SubscriptionStatusChange::fromArray($response['data'] ?? $response);
    }

    /**
     * Mint a single-use payment link that re-collects the customer's card with
     * 3-D Secure (a small, auto-refunded verification amount is charged).
     *
     * Required params: returnUrl, cancelUrl, callbackUrl (callbackUrl is ignored
     * by the API but still required).
     *
     * @param  array<string, mixed> $params
     */
    public function changeCard(string $id, array $params): SubscriptionPaymentLink
    {
        $response = $this->client->request('POST', $this->path($id, 'change-card'), $params);

        return SubscriptionPaymentLink::fromArray($response['data'] ?? $response);
    }

    /**
     * Mint a fresh payment link for a subscription still in pending_initial
     * (its first payment was never completed). 400 for any other status.
     *
     * Required params: returnUrl, cancelUrl, callbackUrl.
     *
     * @param  array<string, mixed> $params
     */
    public function repay(string $id, array $params): SubscriptionPaymentLink
    {
        $response = $this->client->request('POST', $this->path($id, 'repay'), $params);

        return SubscriptionPaymentLink::fromArray($response['data'] ?? $response);
    }

    private function path(string $id, ?string $action = null): string
    {
        return '/api/v1/billing/subscriptions/' . rawurlencode($id) . ($action !== null ? '/' . $action : '');
    }
}
