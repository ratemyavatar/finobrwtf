<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Purchases;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlaceSlotsController extends Controller
{
    public const PRICE = 625;

    public function show()
    {
        return $this->page('Settings/Places', [], 'Places');
    }

    public function purchase(Request $request)
    {
        $user = $request->user();

        $bought = DB::connection('finobe')->transaction(function () use ($user) {
            $paid = User::where('id', $user->id)->where('Dius', '>=', self::PRICE)
                ->update(['Dius' => DB::raw('Dius - ' . self::PRICE), 'slots' => DB::raw('slots + 1')]);

            if ($paid) {
                Purchases::create(['username' => $user->username, 'assetid' => 0, 'serial' => 0, 'author' => 1, 'amount' => -self::PRICE, 'type' => 3]);
            }

            return (bool) $paid;
        });

        $request->session()->put($bought ? 'successv2' : 'error', $bought ? 'Successfully purchased.' : 'You do not have enough Dius to purchase a place slot');

        return redirect('/app/games');
    }
}
