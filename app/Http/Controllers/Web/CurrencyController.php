<?php

namespace App\Http\Controllers\Web;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Asset;
use App\Models\Purchases;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Support\Format;

class CurrencyController extends Controller
{

    public function transactions(Request $request) {
        $title = 'Transaction log';

        if(!Auth::check()) {
            return redirect('/');
        }

        $purchases = [];
        $results = Purchases::where('username', Auth::user()->username)
            ->orderBy('date', 'DESC')
            ->limit(100)
            ->get()
            ->map(fn($item) => $item->toArray());
        
        foreach($results as $result) {
            $result['date'] = date('m/d/Y', strtotime($result['date']));


            switch ($result['type']) {
                case 1:
                case 2:
                    $result['assetname'] = strip_tags(htmlspecialchars(
                        Asset::where('id', $result['assetid'])->value('title')
                    ));
                    break;
            
                case 6:
                    $result['assetname'] = strip_tags(htmlspecialchars(
                        Asset::where('id', $result['assetid'])->value('title')
                    )) . ' (traded)';
                    break;

                case 3:
                    $result['assetname'] = 'Place Slot';
                    break;
            
                case 4:
                    $result['assetname'] = 'Dius';
                    break;
            
                case 5:
                    $result['assetname'] = 'Asset Upload Fee';
                    break;
            
                default:
                    $result['assetname'] = 'Unknown';
                    break;
            }

            $result['uuid'] = User::where('id', $result['author'])->exists() ? User::where('id', $result['author'])->value('id') : false;
            $result['author'] = $result['uuid'] ? User::where('id', $result['author'])->value('username') : htmlspecialchars($result['author']);
            $result['amount'] = Format::number($result['amount']);
            $purchases[] = $result;
        }

        $pageData['purchases'] = $purchases;

        return $this->page('Transactions', $pageData ?? [], $title ?? null);
    }
}