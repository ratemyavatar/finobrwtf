<?php

namespace App\Http\Controllers\Web;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Asset;
use App\Models\Purchases;
use App\Http\Controllers\frontEnd;
use App\Http\Controllers\Controller;
use App\Http\Controllers\dataController as DataController;

class CurrencyController extends Controller
{
    protected $request;

    public function __construct(Request $request) {
        $frontend = new frontEnd($request);
        $this->request = $frontend->getData();
    }

    public function transactions(Request $request) {
        $this->request['data']['embeds']['title'] = 'Transaction log' . $this->request['data']['embeds']['title'];

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $purchases = [];
        $results = Purchases::where('username', $this->request['data']['user']['username'])
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
            $result['amount'] = DataController::formatNumber($result['amount']);
            $purchases[] = $result;
        }

        $this->request['data']['purchases'] = $purchases;

        return view($this->request['data']['user']['version'] . '/Transactions', $this->request);
    }
}