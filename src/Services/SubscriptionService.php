<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\UUID;
use App\Core\Auth;
use Exception;

class SubscriptionService
{
    /**
     * Start a 14-day free trial for a new business tenant.
     */
    public static function startTrial(string $businessId, ?string $planCode = 'starter'): array
    {
        $db = Database::getConnection();

        // Get plan
        $stmtPlan = $db->prepare("SELECT * FROM subscription_plans WHERE code = ? AND is_active = 1");
        $stmtPlan->execute([$planCode]);
        $plan = $stmtPlan->fetch();

        if (!$plan) {
            $stmtPlan = $db->prepare("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY monthly_price ASC LIMIT 1");
            $stmtPlan->execute();
            $plan = $stmtPlan->fetch();
        }

        $trialDays = 14;
        $trialEnd = date('Y-m-d H:i:s', strtotime("+{$trialDays} days"));
        $subId = UUID::v4();

        $stmtSub = $db->prepare("
            INSERT INTO subscriptions (id, business_id, plan_id, billing_cycle, status, starts_at, ends_at, trial_ends_at, created_at, updated_at)
            VALUES (?, ?, ?, 'monthly', 'trial', NOW(), ?, ?, NOW(), NOW())
        ");
        $stmtSub->execute([$subId, $businessId, $plan['id'], $trialEnd, $trialEnd]);

        // Update business trial end
        $db->prepare("UPDATE businesses SET status = 'trial', trial_ends_at = ? WHERE id = ?")
            ->execute([$trialEnd, $businessId]);

        AuditService::log('subscription_trial_started', "14-day trial started on {$plan['name']}", $businessId);

        return [
            'subscription_id' => $subId,
            'plan_name' => $plan['name'],
            'trial_ends_at' => $trialEnd
        ];
    }

    /**
     * Submit manual payment verification request from tenant.
     */
    public static function submitPayment(
        string $businessId,
        string $planId,
        string $billingCycle,
        string $paymentMethod,
        string $referenceNote
    ): string {
        $db = Database::getConnection();

        $stmtPlan = $db->prepare("SELECT * FROM subscription_plans WHERE id = ?");
        $stmtPlan->execute([$planId]);
        $plan = $stmtPlan->fetch();
        if (!$plan) {
            throw new Exception("Invalid subscription plan.");
        }

        $amount = ($billingCycle === 'yearly') ? $plan['yearly_price'] : $plan['monthly_price'];

        // Get current subscription
        $stmtSub = $db->prepare("SELECT id FROM subscriptions WHERE business_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmtSub->execute([$businessId]);
        $subscriptionId = $stmtSub->fetchColumn() ?: UUID::v4();

        $paymentId = UUID::v4();
        $stmt = $db->prepare("
            INSERT INTO subscription_payments (
                id, business_id, subscription_id, plan_id, amount, currency,
                payment_method, reference_note, status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, 'USD', ?, ?, 'pending', NOW(), NOW())
        ");
        $stmt->execute([
            $paymentId, $businessId, $subscriptionId, $planId, $amount,
            $paymentMethod, $referenceNote
        ]);

        AuditService::log('subscription_payment_submitted', "Submitted {$amount} USD payment for manual verification (Ref: {$referenceNote})", $businessId);

        return $paymentId;
    }

    /**
     * Super Admin manually verifies payment and activates / renews subscription.
     */
    public static function verifyPayment(string $paymentId, string $adminUserId): bool
    {
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $stmtPay = $db->prepare("SELECT * FROM subscription_payments WHERE id = ? FOR UPDATE");
            $stmtPay->execute([$paymentId]);
            $payment = $stmtPay->fetch();

            if (!$payment || $payment['status'] !== 'pending') {
                $db->rollBack();
                return false;
            }

            // Mark payment as verified
            $db->prepare("
                UPDATE subscription_payments 
                SET status = 'verified', verified_by_user_id = ?, verified_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ")->execute([$adminUserId, $paymentId]);

            // Extend or activate subscription
            $durationMonths = ($payment['amount'] > 150.00) ? 12 : 1; // monthly vs yearly
            $startsAt = date('Y-m-d H:i:s');
            $endsAt = date('Y-m-d H:i:s', strtotime("+{$durationMonths} months"));

            $stmtSub = $db->prepare("SELECT * FROM subscriptions WHERE id = ?");
            $stmtSub->execute([$payment['subscription_id']]);
            $sub = $stmtSub->fetch();

            if ($sub) {
                // If existing sub is still active, extend from current end date
                if (strtotime($sub['ends_at']) > time()) {
                    $endsAt = date('Y-m-d H:i:s', strtotime("{$sub['ends_at']} +{$durationMonths} months"));
                }
                $db->prepare("
                    UPDATE subscriptions
                    SET plan_id = ?, status = 'active', starts_at = ?, ends_at = ?, updated_at = NOW()
                    WHERE id = ?
                ")->execute([$payment['plan_id'], $startsAt, $endsAt, $sub['id']]);
            } else {
                $db->prepare("
                    INSERT INTO subscriptions (id, business_id, plan_id, billing_cycle, status, starts_at, ends_at, created_at, updated_at)
                    VALUES (?, ?, ?, 'monthly', 'active', ?, ?, NOW(), NOW())
                ")->execute([$payment['subscription_id'], $payment['business_id'], $payment['plan_id'], $startsAt, $endsAt]);
            }

            // Ensure business status is active
            $db->prepare("UPDATE businesses SET status = 'active', updated_at = NOW() WHERE id = ?")
                ->execute([$payment['business_id']]);

            $db->commit();

            AuditService::log('subscription_payment_verified', "Super admin verified payment {$paymentId} for business {$payment['business_id']}", $payment['business_id'], $adminUserId);

            return true;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Check if business has reached its monthly order limits under current plan.
     */
    public static function canCreateOrder(string $businessId): bool
    {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT s.status, sp.max_orders_per_month 
            FROM subscriptions s
            JOIN subscription_plans sp ON s.plan_id = sp.id
            WHERE s.business_id = ?
            ORDER BY s.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$businessId]);
        $sub = $stmt->fetch();

        if (!$sub) {
            return true;
        }

        $maxOrders = (int)$sub['max_orders_per_month'];
        if ($maxOrders <= 0) {
            return true; // Unlimited
        }

        // Count orders created this calendar month
        $stmtCount = $db->prepare("
            SELECT COUNT(*) FROM orders 
            WHERE business_id = ? 
            AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')
        ");
        $stmtCount->execute([$businessId]);
        $currentMonthCount = (int)$stmtCount->fetchColumn();

        return $currentMonthCount < $maxOrders;
    }
}
