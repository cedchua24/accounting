<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustLoanInstallmentRequest;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Services\LoanService;

class LoanInstallmentController extends Controller
{
    public function __construct(private LoanService $loans) {}

    public function update(
        AdjustLoanInstallmentRequest $request,
        Loan $loan,
        LoanInstallment $loanInstallment,
    ) {
        abort_unless((int) $loanInstallment->loan_id === (int) $loan->id, 404);
        $data = $request->validated();
        $asOf = $data['as_of_date'] ?? now()->toDateString();
        unset($data['as_of_date']);

        $adjustment = $this->loans->adjustInstallment(
            $loan,
            $loanInstallment,
            $data,
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'Loan installment adjusted successfully.',
            'adjustment' => $adjustment->load('adjustedByUser:id,name'),
            'loan' => $this->loans->present($loan->fresh(), $asOf),
        ]);
    }

    public function adjustments(Loan $loan, LoanInstallment $loanInstallment)
    {
        abort_unless((int) $loanInstallment->loan_id === (int) $loan->id, 404);

        return $loanInstallment->adjustments()
            ->with('adjustedByUser:id,name')
            ->latest('id')
            ->paginate();
    }
}
