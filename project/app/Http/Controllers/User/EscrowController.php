<?php

namespace App\Http\Controllers\User;

use App\Helpers\MediaHelper;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Currency;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Escrow;

class EscrowController extends Controller
{
    public function index()
    {
        $escrows = Escrow::where('user_id',auth()->id())->paginate(15);
        return view('user.escrow.index',compact('escrows'));  
    }

    public function create()
    {
        $wallets = Wallet::where('user_id',auth()->id())->where('user_type',1)->where('balance', '>', 0)->get();
        $charge = charge('make-escrow');
        return view('user.escrow.create',compact('wallets','charge'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'receiver'  => 'required|email',
            'wallet_id' => 'required|integer',
            'amount'    => 'required|numeric|gt:0',
            'description'    => 'required' 
        ],
        [
            'wallet_id.required' => 'Wallet is required'
        ]);

    
        $receiver = User::where('email',$request->receiver)->first();
        if(!$receiver) return back()->with('error','Recipient not found');
        
        $senderWallet = Wallet::where('id',$request->wallet_id)->where('user_type',1)->where('user_id',auth()->id())->first();

        if(!$senderWallet) return back()->with('error','Your wallet not found');
        
        $currency = Currency::findOrFail($senderWallet->currency->id);
        $charge = charge('make-escrow');  

        $finalCharge = amount(chargeCalc($charge,$request->amount,$currency->rate),$currency->type);
        if($request->pay_charge) $finalAmount =  amount($request->amount + $finalCharge, $currency->type);
        else  $finalAmount =  amount($request->amount, $currency->type);
        
        if($senderWallet->balance < $finalAmount) return back()->with('error','Insufficient balance.');
        
        $senderWallet->balance -= $finalAmount;
        $senderWallet->update();

        $escrow               = new Escrow();
        $escrow->trnx         = str_rand();
        $escrow->user_id      = auth()->id();
        $escrow->recipient_id = $receiver->id;
        $escrow->description  = $request->description;
        $escrow->amount       = $request->amount;
        $escrow->pay_charge   = $request->pay_charge ? 1 : 0;
        $escrow->charge       = $finalCharge;
        $escrow->currency_id  = $currency->id;
        $escrow->save();

        $trnx              = new Transaction();
        $trnx->trnx        = $escrow->trnx;
        $trnx->user_id     = auth()->id();
        $trnx->user_type   = 1;
        $trnx->currency_id = $currency->id;
        $trnx->wallet_id   = $senderWallet->id;
        $trnx->amount      = $finalAmount -$finalCharge;
        $trnx->charge      = $finalCharge;
        $trnx->remark      = 'make_escrow';
        $trnx->type        = '-';
        $trnx->details     = trans('Made escrow to '). $receiver->email;
        $trnx->save();

        return back()->with('success','Escrow has been created successfully');

    }

    public function disputeForm($id)
    {
        $escrow = Escrow::where('id',$id)->firstOrFail();
        if (auth()->id() != $escrow->recipient_id && auth()->id() != $escrow->user_id){
            return back()->with('error','Invalid access');
        }
       
        if(url()->previous() == url('user/escrow-pending')) session()->put('route',route('user.escrow.pending'));
        elseif(url()->previous() == url('user/my-escrow'))  session()->forget('route');
        
        $messages = Dispute::where('escrow_id',$escrow->id)->with('user')->get();
        
        return view('user.escrow.dispute',compact('escrow','messages'));
    }

    public function disputeStore(Request $request,$escrow_id)
    {
        $request->validate(['message'=>'required','file' => 'mimes:png,jpeg,jpg|max:5186']);
        $escrow = Escrow::where('id',$escrow_id)->firstOrFail();
        if (auth()->id() != $escrow->recipient_id && auth()->id() != $escrow->user_id){
            return back()->with('error','Invalid access');
        }
        if($escrow->status == 4) return back()->with('error','Dispute has been closed');
        
        $escrow->status = 3;
        if($escrow->dispute_created == null) $escrow->dispute_created = auth()->id();
        $escrow->save();

        $dispute = new Dispute;
        $dispute->user_id = auth()->id();
        $dispute->escrow_id = $escrow_id;
        $dispute->message = $request->message;
        if($request->file) $dispute->file = MediaHelper::handleMakeImage($request->file);
        $dispute->save();
        return back()->with('success','Replied submitted');
    }

    public function fileDownload($id)
    {
        $dispute = Dispute::findOrFail($id);
        $filepath = 'assets/images/'.$dispute->file;
        $extension = pathinfo($filepath, PATHINFO_EXTENSION);
        $fileName =  @$dispute->user->email.'_file.'.$extension;
        header('Content-type: application/octet-stream');
        header("Content-Disposition: attachment; filename=".$fileName);
        while (ob_get_level()) {
            ob_end_clean();
        }
        readfile($filepath);
    }

    public function release($id)
    {

        $escrow = Escrow::where('id',$id)->where('user_id',auth()->id())->whereIn('status',[0,3])->first();
        $recipient = User::findOrFail($escrow->recipient_id);
        $recipientWallet = Wallet::where('user_id',$recipient->id)
                            ->where('user_type',1)
                            ->where('currency_id',$escrow->currency_id)
                            ->first();

        if(!$recipientWallet){
            $recipientWallet =  Wallet::create(
                [
                    'user_id'      => $recipient->id,
                    'user_type'    => 1,
                    'currency_id'  => $escrow->currency_id,
                    'balance'      => 0
                ]
            );
        } 

        $amount = $escrow->amount;
        if($escrow->pay_charge == 0) $amount -= $escrow->charge;
        
        $recipientWallet->balance += $amount;
        $recipientWallet->update();

        $trnx              = new Transaction();
        $trnx->trnx        = $escrow->trnx;
        $trnx->user_id     = $recipient->id;
        $trnx->user_type   = 1;
        $trnx->currency_id = $escrow->currency_id;
        $trnx->amount      = $amount;
        $trnx->charge      = $escrow->pay_charge == 0 ? $escrow->charge : 0;
        $trnx->remark      = 'make_escrow';
        $trnx->type        = '+';
        $trnx->details     = trans('Received escrow money '). $recipient->email;
        $trnx->save();

        $escrow->status = 1;
        $escrow->save();

        return back()->with('success','Escrow has been released');

    }

    public function pending()
    {
        $escrows = Escrow::where('recipient_id',auth()->id())->latest()->paginate(15);
        return view('user.escrow.pending',compact('escrows'));
    }
}
