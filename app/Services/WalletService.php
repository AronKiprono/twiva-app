<?php

namespace App\Services;

class WalletService
{
    /**
     * Create a new class instance.
     */
    public function creditPayment(array $paymentData): void
    {
        // Use a database transaction to keep the financial changes atomic.
        // Ensure one payment produces no more than one committed wallet credit.
        // Handle two concurrent callbacks for the same payment.
        // Use integer minor units for money.
        // Reject or flag an invalid payment state rather than silently crediting it.
        // Handle an already-processed payment without double-crediting.
        // Use Laravel's Query Builder or Eloquent.

        // Use a database transaction to ensure atomicity
        DB::transaction(function () use ($event) {
            //lock exact payment record to prevent double processing
            $payment = Payment::where('reference', $event->reference)
                ->lockForUpdate()
                ->firstOrFail();

            //No trusting an event just because the reference matches
            // an existing payment record. Verify the payment provider, amount, currency.
            if ($payment->provider !== $event->provider || 
                $payment->amount_minor !== $event->amount_minor || 
                $payment->currency !== $event->currency) {
                // Log the invalid payment state for auditing and debugging.
                Log::warning('Invalid payment state for crediting.', [
                    'payment_id' => $payment->id,
                    'status' => $payment->status,
                ]);
                throw new \Exception('Invalid payment details.');
            }

            // Check if the payment has already been processed
            if ($payment->status === 'processed') {
                // Log the already processed payment for auditing and debugging.
                Log::info('Payment already processed.', [
                    'payment_id' => $payment->id,
                ]);
                return; // Exit without double-crediting
            }

            // Update the payment status to 'processed'
            $payment->status = 'processed';
            $payment->save();

            // Create a new wallet transaction record
            $walletTransaction = new WalletTransaction();
            $walletTransaction->wallet_id = $payment->wallet_id;
            $walletTransaction->amount_minor = $payment->amount_minor;
            $walletTransaction->type = 'credit';
            $walletTransaction->save();

            // Update the user's wallet balance
            $wallet = Wallet::findOrFail($payment->wallet_id);
            $wallet->balance_minor += $payment->amount_minor;
            $wallet->save();

            // Log the successful crediting for auditing and debugging.
            Log::info('Payment credited to wallet successfully.', [
                'payment_id' => $payment->id,
                'wallet_id' => $wallet->id,
                'new_balance_minor' => $wallet->balance_minor,
            ]);

            // Queue a notification to the user about the successful crediting of their wallet
            Notification::send($wallet->user, new WalletCreditedNotification($payment));
        });
    }
}
