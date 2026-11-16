<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PaymentCallbackController extends Controller
{
    // verify the payment callback and webhook signatures to ensure it is from valid source
    // then call the WalletService to credit the payment to the user's wallet
    public function handlePaymentCallback(Request $request,
    PaymentGatewayVerifier $verifier)
    {
        // Verify authenticity using the provider's protocol,
        // then normalize and validate the event.
        $event = $verifier->verifyAndNormalize($request);

        //if verified, call the WalletService to credit the payment to the user's wallet
        //pass whole callback event to the WalletService to handle the payment crediting
        if ($event->isVerified()) {
            $paymentId = $event->getPaymentId();
            $walletService = new WalletService();
            $walletService->creditPayment($event);
        } else {
            // Log the failed verification attempt for auditing and debugging.
            Log::warning('Payment callback verification failed.', [
                'request' => $request->all(),
            ]);
            // Optionally, respond with an error status to the payment provider.
            return response()->json(['error' => 'Invalid callback signature.'], 400);
        }
    }
}
