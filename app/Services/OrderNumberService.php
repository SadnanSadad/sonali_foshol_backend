<?php

namespace App\Services;

use App\Models\OrderSequence;
use Illuminate\Support\Facades\DB;

class OrderNumberService
{
    public function next(): string
    {
        $year = (int) now('Asia/Dhaka')->format('Y');

        $number = DB::transaction(function () use ($year) {
            $row = OrderSequence::query()->where('year', $year)->lockForUpdate()->first();

            if (! $row) {
                $row = OrderSequence::query()->create([
                    'year' => $year,
                    'next_number' => 2,
                ]);

                return 1;
            }

            $current = $row->next_number;
            $row->increment('next_number');

            return $current;
        });

        return sprintf('SFO-%d-%04d', $year, $number);
    }
}
