<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserChangePassword;
use App\Http\Requests\User\UserRedeemGiftCard;
use App\Http\Requests\User\UserTransfer;
use App\Http\Requests\User\UserUpdate;
use App\Models\Giftcard;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Coupon;
use App\Services\AuthService;
use App\Services\UserService;
use App\Services\RedemptionCodeService;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\TelegramService;
use App\Services\OrderNotifyService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use App\Jobs\OrderHandleJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    public function getActiveSession(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $authService = new AuthService($user);
        return response([
            'data' => $authService->getSessions()
        ]);
    }

    public function removeActiveSession(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $authService = new AuthService($user);
        return response([
            'data' => $authService->removeSession($request->input('session_id'))
        ]);
    }

    public function checkLogin(Request $request)
    {
        $data = [
            'is_login' => $request->user['id'] ? true : false
        ];
        if ($request->user['is_admin']) {
            $data['is_admin'] = true;
        }
        return response([
            'data' => $data
        ]);
    }

    public function changePassword(UserChangePassword $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $request->input('old_password'),
            $user->password
        )) {
            abort(500, __('The old password is wrong'));
        }
        $user->password = password_hash($request->input('new_password'), PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;
        if (!$user->save()) {
            abort(500, __('Save failed'));
        }
        $authService = new AuthService($user);
        $authService->removeAllSession();
        return response([
            'data' => true
        ]);
    }

    public function redeemgiftcardNew(UserRedeemGiftCard $request)
    {
        DB::beginTransaction();

        try {
            $user = User::find($request->user['id']);
            if (!$user) {
                abort(500, __('The user does not exist'));
            }
            $giftcard_input = $request->giftcard;
            $giftcard = Giftcard::where('code', $giftcard_input)->first();

            if (!$giftcard) {
                abort(500, __('The gift card does not exist'));
            }

            $currentTime = time();
            if ($giftcard->started_at && $currentTime < $giftcard->started_at) {
                abort(500, __('The gift card is not yet valid'));
            }

            if ($giftcard->ended_at && $currentTime > $giftcard->ended_at) {
                abort(500, __('The gift card has expired'));
            }

            if ($giftcard->limit_use !== null) {
                if (!is_numeric($giftcard->limit_use) || $giftcard->limit_use <= 0) {
                    abort(500, __('The gift card usage limit has been reached'));
                }
            }

            $usedUserIds = $giftcard->used_user_ids ? json_decode($giftcard->used_user_ids, true) : [];
            if (!is_array($usedUserIds)) {
                $usedUserIds = [];
            }

            if (in_array($user->id, $usedUserIds)) {
                abort(500, __('The gift card has already been used by this user'));
            }

            $usedUserIds[] = $user->id;
            $giftcard->used_user_ids = json_encode($usedUserIds);

            switch ($giftcard->type) {
                case 1:
                    $user->balance += $giftcard->value;
                    break;
                case 2:
                    if ($user->expired_at !== null) {
                        if ($user->expired_at <= $currentTime) {
                            $user->expired_at = $currentTime + $giftcard->value * 86400;
                        } else {
                            $user->expired_at += $giftcard->value * 86400;
                        }
                    } else {
                        abort(500, __('Not suitable gift card type'));
                    }
                    break;
                case 3:
                    $user->transfer_enable += $giftcard->value * 1073741824;
                    break;
                case 4:
                    $user->u = 0;
                    $user->d = 0;
                    break;
                case 5:
                    if ($user->plan_id == null || ($user->expired_at !== null && $user->expired_at < $currentTime)) {
                        $plan = Plan::where('id', $giftcard->plan_id)->first();
                        $user->plan_id = $plan->id;
                        $user->group_id = $plan->group_id;
                        $user->transfer_enable = $plan->transfer_enable * 1073741824;
                        $user->device_limit = $plan->device_limit;
                        $user->u = 0;
                        $user->d = 0;
                        if($giftcard->value == 0) {
                            $user->expired_at = null;
                        } else {
                            $user->expired_at = $currentTime + $giftcard->value * 86400;
                        }
                    } else {
                        abort(500, __('Not suitable gift card type'));
                    }
                    break;
                default:
                    abort(500, __('Unknown gift card type'));
            }

            if ($giftcard->limit_use !== null) {
                $giftcard->limit_use -= 1;
            }

            if (!$user->save() || !$giftcard->save()) {
                throw new \Exception(__('Save failed'));
            }

            DB::commit();

            return response([
                'data' => true,
                'type' => $giftcard->type,
                'value' => $giftcard->value
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }
    }

    public function info(Request $request)
    {
        $user = User::where('id', $request->user['id'])
            ->select([
                'email',
                'transfer_enable',
                'device_limit',
                'last_login_at',
                'created_at',
                'banned',
                'auto_renewal',
                'remind_expire',
                'remind_traffic',
                'expired_at',
                'balance',
                'commission_balance',
                'plan_id',
                'discount',
                'commission_rate',
                'telegram_id',
                'uuid'
            ])
            ->first();
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $user['avatar_url'] = 'https://cravatar.cn/avatar/' . md5($user->email) . '?s=64&d=identicon';
        return response([
            'data' => $user
        ]);
    }

    public function getStat(Request $request)
    {
        $stat = [
            Order::where('status', 0)
                ->where('user_id', $request->user['id'])
                ->count(),
            Ticket::where('status', 0)
                ->where('user_id', $request->user['id'])
                ->count(),
            User::where('invite_user_id', $request->user['id'])
                ->count()
        ];
        return response([
            'data' => $stat
        ]);
    }

    public function getSubscribe(Request $request)
    {
        $user = User::where('id', $request->user['id'])
            ->select([
                'plan_id',
                'token',
                'expired_at',
                'u',
                'd',
                'transfer_enable',
                'device_limit',
                'email',
                'uuid',
                'remarks'
            ])
            ->first();
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if ($user->plan_id) {
            $user['plan'] = Plan::find($user->plan_id);
            if (!$user['plan']) {
                abort(500, __('Subscription plan does not exist'));
            }
        }

        //统计在线设备
        $countalive = 0;
        $ips_array = Cache::get('ALIVE_IP_USER_' . $request->user['id']);
        if ($ips_array) {
            $countalive = $ips_array['alive_ip'];
        }
        $user['alive_ip'] = $countalive;

        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);

        $userService = new UserService();
        $user['reset_day'] = $userService->getResetDay($user);
        return response([
            'data' => $user
        ]);
    }

    public function unbindTelegram(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if (!$user->update(['telegram_id' => null])) {
            abort(500, __('Unbind telegram failed'));
        }
        return response([
            'data' => true
        ]);
    }

    public function resetSecurity(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if (!$user->save()) {
            abort(500, __('Reset failed'));
        }
        return response([
            'data' => Helper::getSubscribeUrl($user['token'])
        ]);
    }

    public function update(UserUpdate $request)
    {
        $updateData = $request->only([
            'auto_renewal',
            'remind_expire',
            'remind_traffic'
        ]);

        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        try {
            $user->update($updateData);
        } catch (\Exception $e) {
            abort(500, __('Save failed'));
        }

        return response([
            'data' => true
        ]);
    }

    public function transfer(UserTransfer $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if ($request->input('transfer_amount') > $user->commission_balance) {
            abort(500, __('Insufficient commission balance'));
        }
        DB::beginTransaction();
        $order = new Order();
        $orderService = new OrderService($order);
        $order->user_id = $request->user['id'];
        $order->plan_id = 0;
        $order->period = 'deposit';
        $order->trade_no = Helper::generateOrderNo();
        $order->total_amount = $request->input('transfer_amount');

        $orderService->setOrderType($user);
        $orderService->setInvite($user);

        $user->commission_balance = $user->commission_balance - $request->input('transfer_amount');
        $user->balance = $user->balance + $request->input('transfer_amount');
        $order->status = 3;
        if (!$order->save()||!$user->save()) {
            DB::rollback();
            abort(500, __('Transfer failed'));
        }

        DB::commit();

        return response([
            'data' => true
        ]);
    }

    public function getQuickLoginUrl(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user->id, 60);
        $redirect = '/#/login?verify=' . $code . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
        if (config('v2board.app_url')) {
            $url = config('v2board.app_url') . $redirect;
        } else {
            $url = url($redirect);
        }
        return response([
            'data' => $url
        ]);
    }

    public function redeemPlan(Request $request)
    {
        //兑换码验证
        $code = $request->input('redeem_code');
        $user_id = $request->user['id'];
        $user = User::find($user_id);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $redemptionCodeService = new RedemptionCodeService();
        $redeemData = $redemptionCodeService->validate($code);
        $plan = Plan::find($redeemData['plan_id']);
        if (!$plan) {
            abort(500, __('Subscription plan does not exist'));
        }
        if ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) {
            abort(500, __('This subscription has been sold out, please choose another subscription'));
        }
        if ($plan[$redeemData['period']] === NULL) {
            abort(500, __('This payment period cannot be purchased, please choose another cycle'));
        }
        DB::beginTransaction();
        $order = new Order();
        $orderService = new OrderService($order);
        $order->user_id = $user->id;
        $order->plan_id = $plan->id;
        $order->period = $redeemData['period'];
        $order->trade_no = Helper::guid();
        $order->total_amount = 0;
        $order->type = 5;
        $order->status = 0;
        $order->invite_user_id = $user->invite_user_id;
        $couponService = new CouponService($code);
        if (!$couponService->use($order)) {
            DB::rollBack();
            abort(500, __('Coupon failed'));
        }
        $order->coupon_id = $couponService->getId();
        $orderService->setOrderType($user);
        if (!$order->save()) {
            DB::rollback();
            abort(500, __('Failed to update order amount'));
        }
        if (!$user->save()) {
            DB::rollBack();
            abort(500, __('绑定邀请关系失败'));
        }
        $orderService->paid('redeem_code:'.$code);
        DB::commit();
        return response([
            'data' => [
                'state' => true,
                'msg' => '兑换成功'
            ]
        ]);
    }
    
    public function redeemgiftcard(UserRedeemGiftCard $request)
    {
        Log::info('开始兑换礼品卡流程', ['request' => $request->all()]);

        $code = $request->giftcard;
        $user_id = $request->user['id'];
        $user = User::find($user_id);

        if (!$user) {
            Log::error('用户不存在', ['user_id' => $user_id]);
            abort(500, __('The user does not exist'));
        }

        Log::info('用户存在', ['user_id' => $user->id]);

        $redemptionCodeService = new RedemptionCodeService();
        $redeemData = $redemptionCodeService->validate($code);

        Log::info('兑换码验证通过', ['redeemData' => $redeemData]);

        $plan = Plan::find($redeemData['plan_id']);
        if (!$plan) {
            Log::error('订阅计划不存在', ['plan_id' => $redeemData['plan_id']]);
            abort(500, __('Subscription plan does not exist'));
        }

        if ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) {
            Log::error('订阅计划不可用或已售罄', ['plan_id' => $plan->id]);
            abort(500, __('This subscription has been sold out, please choose another subscription'));
        }

        if ($plan[$redeemData['period']] === NULL) {
            Log::error('周期价格为空', ['period' => $redeemData['period']]);
            abort(500, __('This payment period cannot be purchased, please choose another cycle'));
        }
        $value = match ($redeemData['period']) {
            'month_price' => 30,
            'quarter_price' => 90,
            'half_year_price' => 180,
            'year_price' => 365,
            default => 30
        };

        DB::beginTransaction();

        try {
            if (!$user->save()) {
                DB::rollBack();
                abort(500, __('绑定邀请关系失败'));
            }
            $order = new Order();
            $orderService = new OrderService($order);
            $order->user_id = $user->id;
            $order->plan_id = $plan->id;
            $order->period = $redeemData['period'];
            $order->trade_no = Helper::guid();
            $order->total_amount = 0;
            $order->type = 5;
            $order->status = 0;
            $order->invite_user_id = $user->invite_user_id;

            $couponService = new CouponService($code);
            if (!$user->save()) {
                DB::rollBack();
                abort(500, __('绑定邀请关系失败'));
            }
            if (!$couponService->use($order)) {
                DB::rollBack();
                Log::error('兑换码使用失败', ['code' => $code]);
                abort(500, __('Coupon failed'));
            }

            $order->coupon_id = $couponService->getId();
            $orderService->setOrderType($user);
            if (!$order->save()) {
                DB::rollback();
                Log::error('订单保存失败', ['order' => $order]);
                abort(500, __('Failed to update order amount'));
            }

            Log::info('订单保存成功', ['order_id' => $order->id]);

            OrderHandleJob::dispatchNow($order->trade_no);
            
            $orderService->paid('redeem_code:'.$code);
            DB::commit();
            Log::info('兑换流程完成', ['user_id' => $user->id, 'trade_no' => $order->trade_no]);
            return response([
                'data' => true,
                'type' => 5,
                'value' => $value
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('兑换流程异常', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            abort(500, 'Server Error');
        }
    }
}
