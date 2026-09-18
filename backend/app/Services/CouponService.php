<?php

namespace App\Services;

use App\Audit\Activity;
use App\Enums\CouponType;
use App\Models\Coupon;
use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CouponService
{
    public function __construct(private readonly Activity $activity) {}

    /** @param  array<string, mixed>  $data */
    public function create(Organization $organization, array $data): Coupon
    {
        $data['code'] = strtoupper(trim((string) $data['code']));

        if (Coupon::query()->forOrganization($organization)->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'Купон с таким кодом уже есть.']);
        }

        if (($data['type'] ?? null) === CouponType::Fixed->value && empty($data['currency'])) {
            throw ValidationException::withMessages(['currency' => 'Для фиксированной скидки нужна валюта.']);
        }

        $coupon = Coupon::create(['organization_id' => $organization->id, ...$data]);
        $this->activity->record('coupon.created', $organization->id, $coupon, ['code' => $coupon->code, 'type' => $coupon->type->value]);

        return $coupon;
    }

    /**
     * Проверка и списание одного использования под lock купона: два параллельных запроса
     * не превысят max_redemptions.
     */
    public function redeem(Coupon $coupon, Subscription $subscription): Coupon
    {
        return DB::transaction(function () use ($coupon, $subscription) {
            $coupon = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);

            $this->assertRedeemable($coupon, $subscription->customer_id, $subscription->currency);

            if ($subscription->coupon_id) {
                throw ValidationException::withMessages(['coupon' => 'У подписки уже есть купон.']);
            }

            $claimed = Coupon::query()
                ->whereKey($coupon->id)
                ->where(fn ($q) => $q->whereNull('max_redemptions')->orWhereColumn('times_redeemed', '<', 'max_redemptions'))
                ->update(['times_redeemed' => DB::raw('times_redeemed + 1'), 'updated_at' => now()]);

            if ($claimed !== 1) {
                throw ValidationException::withMessages(['coupon' => 'Лимит использований купона исчерпан.']);
            }

            $coupon->redemptions()->create([
                'organization_id' => $coupon->organization_id,
                'customer_id' => $subscription->customer_id,
                'subscription_id' => $subscription->id,
                'redeemed_at' => now(),
            ]);
            $subscription->update(['coupon_id' => $coupon->id]);
            $this->activity->record('coupon.redeemed', $coupon->organization_id, $subscription, ['code' => $coupon->code]);

            return $coupon->refresh();
        });
    }

    public function findByCode(Organization $organization, string $code): ?Coupon
    {
        return Coupon::query()->forOrganization($organization)->where('code', strtoupper(trim($code)))->first();
    }

    public function assertRedeemable(Coupon $coupon, string $customerId, string $currency): void
    {
        if (! $coupon->active) {
            throw ValidationException::withMessages(['coupon' => 'Купон отключён.']);
        }
        if ($coupon->isExpired()) {
            throw ValidationException::withMessages(['coupon' => 'Срок действия купона истёк.']);
        }
        if ($coupon->isExhausted()) {
            throw ValidationException::withMessages(['coupon' => 'Лимит использований купона исчерпан.']);
        }
        if ($coupon->customer_id && $coupon->customer_id !== $customerId) {
            throw ValidationException::withMessages(['coupon' => 'Купон выдан другому клиенту.']);
        }
        if ($coupon->type === CouponType::Fixed && $coupon->currency !== $currency) {
            throw ValidationException::withMessages(['coupon' => "Купон в {$coupon->currency}, а подписка в {$currency}."]);
        }
    }
}
