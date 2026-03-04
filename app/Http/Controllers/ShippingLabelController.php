<?php

namespace App\Http\Controllers;

use App\Actions\Order\FetchShippingLabelAction;
use App\Models\Order\Order;
use Illuminate\Http\Response;

class ShippingLabelController extends Controller
{
    public function show(Order $order, FetchShippingLabelAction $action): Response
    {
        try {
            $label = $action->execute($order);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response($label->labelContent, 200)
            ->header('Content-Type', $label->getContentType())
            ->header('Content-Disposition', 'inline; filename="label-'.$order->order_number.$label->getFileExtension().'"');
    }
}
