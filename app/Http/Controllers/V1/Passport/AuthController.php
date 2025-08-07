<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\User;
use App\Models\Order;
use App\Services\AuthService;
use App\Services\OrderService;
use App\Services\RedemptionCodeService;
use App\Services\CouponService;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use ReCaptcha\ReCaptcha;

class AuthController extends Controller
{
    public function loginWithMailLink(Request $request)
    {
        if (!(int)config('v2board.login_with_mail_link_enable')) {
            abort(404);
        }
        $params = $request->validate([
            'email' => 'required|email:strict',
            'redirect' => 'nullable'
        ]);

        if (Cache::get(CacheKey::get('LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP', $params['email']))) {
            abort(500, __('Sending frequently, please try again later'));
        }

        $user = User::where('email', $params['email'])->first();
        if (!$user) {
            return response([
                'data' => true
            ]);
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user->id, 300);
        Cache::put(CacheKey::get('LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP', $params['email']), time(), 60);


        $redirect = '/#/login?verify=' . $code . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
        if (config('v2board.app_url')) {
            $link = config('v2board.app_url') . $redirect;
        } else {
            $link = url($redirect);
        }

        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('Login to :name', [
                'name' => config('v2board.app_name', 'V2Board')
            ]),
            'template_name' => 'login',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'link' => $link,
                'url' => config('v2board.app_url')
            ]
        ]);

        return response([
            'data' => $link
        ]);

    }

    public function register(AuthRegister $request)
    {
        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            $registerCountByIP = Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip())) ?? 0;
            if ((int)$registerCountByIP >= (int)config('v2board.register_limit_count', 3)) {
                abort(500, __('Register frequently, please try again after :minute minute', [
                    'minute' => config('v2board.register_limit_expire', 60)
                ]));
            }
        }
        if ((int)config('v2board.recaptcha_enable', 0)) {
            $recaptcha = new ReCaptcha(config('v2board.recaptcha_key'));
            $recaptchaResp = $recaptcha->verify($request->input('recaptcha_data'));
            if (!$recaptchaResp->isSuccess()) {
                abort(500, __('Invalid code is incorrect'));
            }
        }
        if ((int)config('v2board.email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify(
                $request->input('email'),
                config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))
            ) {
                abort(500, __('Email suffix is not in the Whitelist'));
            }
        }
        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $request->input('email'))[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                abort(500, __('Gmail alias is not supported'));
            }
        }
        if ((int)config('v2board.stop_register', 0)) {
            abort(500, __('Registration has closed'));
        }
        if ((int)config('v2board.invite_force', 0)) {
            if (empty($request->input('invite_code'))) {
                abort(500, __('You must use the invitation code to register'));
            }
        }
        if ((int)config('v2board.email_verify', 0)) {
            if (empty($request->input('email_code'))) {
                abort(500, __('Email verification code cannot be empty'));
            }
            if ((string)Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email'))) !== (string)$request->input('email_code')) {
                abort(500, __('Incorrect email verification code'));
            }
        }
        DB::beginTransaction();
        $email = $request->input('email');
        $password = $request->input('password');
        $code = $request->input('code');
        $inviteCode = $request->input('invite_code');
        if (User::where('email', $email)->exists()) {
            abort(500, '该邮箱已被注册');
        }
        //注册IP
        $ip = $request->ip();
        $cacheKey = 'TRIAL_IP:' . $ip;
        if ($inviteCode) {
            $inviteCode = InviteCode::where('code', $inviteCode)->where('status', 0)->first();
            if (!$inviteCode) {
                abort(500, __('Invalid invitation code'));
            }
            if (!(int) config('v2board.invite_never_expire', 0)) {
                $inviteCode->status = 1;
                $inviteCode->save();
            }
        }

        
        //注册
        $user = new User();
        $user->email = $email;
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->is_admin = 0;
        if ($inviteCode) {
            $user->invite_user_id = $inviteCode->user_id ? $inviteCode->user_id : null;
        }
        $isQQEmail = Str::endsWith($email, ['@qq.com', '@qq.cn', '@qq.com.cn']);
        $emailPrefix = Str::before($email, '@');
        $isNumericQQ = $isQQEmail && ctype_digit($emailPrefix);
        if (Cache::has($cacheKey) && !$isNumericQQ) {
            \Log::info('跳过试用套餐发放（IP已存在缓存，非纯数字QQ邮箱）', [
                    'email' => $email,
                    'ip' => $ip,
                    'cache_key' => $cacheKey
            ]);
        }
        else{
            if ((int)config('v2board.try_out_plan_id', 0)) {
                $plan = Plan::find(config('v2board.try_out_plan_id'));
                if ($plan) {
                    $user->transfer_enable = $plan->transfer_enable * 1073741824;
                    $user->plan_id = $plan->id;
                    $user->group_id = $plan->group_id;
                    $user->expired_at = time() + (config('v2board.try_out_hour', 1) * 3600);
                    $user->speed_limit = $plan->speed_limit;
                    $user->device_limit = $plan->device_limit;
                }
            }
        }
        if (!$user->save()) {
            DB::rollBack();
            abort(500, __('Register failed'));
        }
        if ($code) {
            $redemptionCodeService = new RedemptionCodeService();
            $redeemData = $redemptionCodeService->validate($code);
            $plan = Plan::find($redeemData['plan_id']);
            if (!$plan) {
                DB::rollBack();
                abort(500, __('Subscription plan does not exist'));
            }

            if ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) {
                DB::rollBack();
                abort(500, __('This subscription has been sold out, please choose another subscription'));
            }

            if ($plan[$redeemData['period']] === NULL) {
                DB::rollBack();
                abort(500, __('This payment period cannot be purchased, please choose another cycle'));
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
            if ($inviteCode) {
                $order->invite_user_id = $inviteCode->user_id ? $inviteCode->user_id : null;
            }
            if ($code) {
                $couponService = new CouponService($code);
                if (!$couponService->use($order)) {
                    DB::rollBack();
                    abort(500, __('Coupon failed'));
                }
                $order->coupon_id = $couponService->getId();
            }
            if (!$orderService->paid('redeem_code:'.$code)) {
                DB::rollback();
                abort(500, __('Failed to update order amount'));
            }
        }
        DB::commit();
        $logData['ip'] = $request->ip();
        $logData['user_id'] = $user->id;
        $logData['user_agent'] = $request->header('User-Agent');
        \Log::info('用户注册成功: ' . json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        //邀请人奖励
        $inviteGiveType = (int)config('v2board.is_Invitation_to_give', 0);
        if ($inviteGiveType === 1 || $inviteGiveType === 3) {
            $this->handleInviteReward($user);
        }
        $authService = new AuthService($user);
        Cache::put($cacheKey, true, now()->addDays(90));
        return response()->json([
            'data' => $authService->generateAuthData($request)
        ]);
    }

    public function handleInviteReward(User $user)
    {
        try {
            // 获取邀请人
            $inviter = User::find($user->invite_user_id);
            if (!$inviter || (int)config('v2board.try_out_plan_id') == $inviter->plan_id) {
                return;
            }
            
            // 获取奖励套餐(配置中设置的赠送套餐)
            $rewardPlan = Plan::find((int)config('v2board.complimentary_packages'));
            if (!$rewardPlan) {
                return;
            }
            
            // 获取邀请人当前套餐
            $inviterCurrentPlan = Plan::find($inviter->plan_id);
            if (!$inviterCurrentPlan) {
                return;
            }

            // 检查套餐价格有效性
            $rewardHasValidPrice = $rewardPlan->month_price > 0 || 
                $rewardPlan->quarter_price > 0 || 
                $rewardPlan->half_year_price > 0 || 
                $rewardPlan->year_price > 0 || 
                $rewardPlan->two_year_price > 0 || 
                $rewardPlan->three_year_price > 0 || 
                $rewardPlan->onetime_price > 0;

            $inviterHasValidPrice = $inviterCurrentPlan->month_price > 0 || 
                $inviterCurrentPlan->quarter_price > 0 || 
                $inviterCurrentPlan->half_year_price > 0 || 
                $inviterCurrentPlan->year_price > 0 || 
                $inviterCurrentPlan->two_year_price > 0 || 
                $inviterCurrentPlan->three_year_price > 0 || 
                $inviterCurrentPlan->onetime_price > 0;

            if (!$inviterHasValidPrice || !$rewardHasValidPrice) {
                \Log::warning('套餐价格异常，无法计算奖励', [
                    'inviter_id' => $inviter->id,
                    'reward_plan_id' => $rewardPlan->id,
                    'current_plan_id' => $inviter->plan_id
                ]);
                return; // 避免除零错误
            }
            
            DB::transaction(function () use ($user, $rewardPlan, $inviterCurrentPlan, $inviter) {
                // 初始化时间
                $currentTime = time();
                if ($inviter->expired_at === null || $inviter->expired_at < $currentTime) {
                    $inviter->expired_at = $currentTime;
                }
                
                // 计算奖励套餐的月均价值
                $rewardMonthlyValue = $this->getMonthlyValue($rewardPlan);
                
                // 计算邀请人当前套餐的月均价值
                $inviterMonthlyValue = $this->getMonthlyValue($inviterCurrentPlan);
                
                // 计算套餐价值比例：奖励套餐月均价值 / 邀请人套餐月均价值
                $priceRatio = $rewardMonthlyValue / $inviterMonthlyValue;
                
                // 配置的赠送小时数
                $configHours = (int)config('v2board.complimentary_package_duration',0);
                
                // 根据价值比例折算实际赠送时间
                $adjustedHours = $configHours * $priceRatio;
                $add_seconds = $adjustedHours * 3600; // 转换为秒
                
                // 更新邀请人到期时间
                $inviter->expired_at = $inviter->expired_at + $add_seconds;
                
                // 将秒数转换为天数（用于显示）
                $calculated_days = $add_seconds / 86400;
                $formatted_days = number_format($calculated_days, 2, '.', '');
                
                // 创建赠送订单
                $order = new Order();
                $orderService = new OrderService($order);
                $order->user_id = $inviter->id;
                $order->plan_id = $inviter->plan_id;
                $order->period = '';
                $order->trade_no = Helper::guid();
                $order->total_amount = 0;
                $order->status = 0;
                $order->type = 6;
                $order->gift_days = $formatted_days;
                $orderService->paid('invite');   
                \Log::info('注册邀请奖励发放成功', [
                    'user_id' => $user->id,
                    'inviter_id' => $inviter->id,
                    'order_id' => $order->id,
                    'reward_monthly_value' => $rewardMonthlyValue,
                    'inviter_monthly_value' => $inviterMonthlyValue,
                    'price_ratio' => $priceRatio,
                    'config_hours' => $configHours,
                    'adjusted_hours' => $adjustedHours,
                    'gift_days' => $formatted_days
                ]);
            });
        } catch (\Exception $e) {
            \Log::error('处理邀请奖励失败', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'inviter_id' => $user->invite_user_id,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    private function getMonthlyValue($plan)
    {
        $monthlyValues = [];
        
        // 月付价格
        if ($plan->month_price > 0) {
            $monthlyValues[] = $plan->month_price;
        }
        
        // 季付价格折算为月价格
        if ($plan->quarter_price > 0) {
            $monthlyValues[] = $plan->quarter_price / 3;
        }
        
        // 半年付价格折算为月价格
        if ($plan->half_year_price > 0) {
            $monthlyValues[] = $plan->half_year_price / 6;
        }
        
        // 年付价格折算为月价格
        if ($plan->year_price > 0) {
            $monthlyValues[] = $plan->year_price / 12;
        }
        
        // 两年付价格折算为月价格
        if ($plan->two_year_price > 0) {
            $monthlyValues[] = $plan->two_year_price / 24;
        }
        
        // 三年付价格折算为月价格
        if ($plan->three_year_price > 0) {
            $monthlyValues[] = $plan->three_year_price / 36;
        }
        
        // 一次性付费，假设等同于年付折算
        if ($plan->onetime_price > 0) {
            $monthlyValues[] = $plan->onetime_price / 12;
        }
        
        // 如果没有有效价格，返回1以避免除零错误
        if (empty($monthlyValues)) {
            return 1;
        }
        return max($monthlyValues);
    }

    public function login(AuthLogin $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');

        if ((int)config('v2board.password_limit_enable', 1)) {
            $passwordErrorCount = (int)Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
            if ($passwordErrorCount >= (int)config('v2board.password_limit_count', 5)) {
                abort(500, __('There are too many password errors, please try again after :minute minutes.', [
                    'minute' => config('v2board.password_limit_expire', 60)
                ]));
            }
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            abort(500, __('Incorrect email or password'));
        }
        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $password,
            $user->password)
        ) {
            if ((int)config('v2board.password_limit_enable')) {
                Cache::put(
                    CacheKey::get('PASSWORD_ERROR_LIMIT', $email),
                    (int)$passwordErrorCount + 1,
                    60 * (int)config('v2board.password_limit_expire', 60)
                );
            }
            abort(500, __('Incorrect email or password'));
        }

        if ($user->banned) {
            abort(500, __('Your account has been suspended'));
        }

        $authService = new AuthService($user);
        return response([
            'data' => $authService->generateAuthData($request)
        ]);
    }

    public function token2Login(Request $request)
    {
        if ($request->input('token')) {
            $redirect = '/#/login?verify=' . $request->input('token') . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
            if (config('v2board.app_url')) {
                $location = config('v2board.app_url') . $redirect;
            } else {
                $location = url($redirect);
            }
            return redirect()->to($location)->send();
        }

        if ($request->input('verify')) {
            $key =  CacheKey::get('TEMP_TOKEN', $request->input('verify'));
            $userId = Cache::get($key);
            if (!$userId) {
                abort(500, __('Token error'));
            }
            $user = User::find($userId);
            if (!$user) {
                abort(500, __('The user does not '));
            }
            if ($user->banned) {
                abort(500, __('Your account has been suspended'));
            }
            Cache::forget($key);
            $authService = new AuthService($user);
            return response([
                'data' => $authService->generateAuthData($request)
            ]);
        }
    }

    public function getQuickLoginUrl(Request $request)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        if (!$authorization) abort(403, '未登录或登陆已过期');

        $user = AuthService::decryptAuthData($authorization);
        if (!$user) abort(403, '未登录或登陆已过期');

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user['id'], 60);
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

    public function forget(AuthForget $request)
    {
        $forgetRequestLimitKey = CacheKey::get('FORGET_REQUEST_LIMIT', $request->input('email'));
        $forgetRequestLimit = (int)Cache::get($forgetRequestLimitKey);
        if ($forgetRequestLimit >= 3) abort(500, __('Reset failed, Please try again later'));
        if ((string)Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email'))) !== (string)$request->input('email_code')) {
            Cache::put($forgetRequestLimitKey, $forgetRequestLimit ? $forgetRequestLimit + 1 : 1, 300);
            abort(500, __('Incorrect email verification code'));
        }
        $user = User::where('email', $request->input('email'))->first();
        if (!$user) {
            abort(500, __('This email is not registered in the system'));
        }
        $user->password = password_hash($request->input('password'), PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;
        if (!$user->save()) {
            abort(500, __('Reset failed'));
        }
        Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email')));
        $authService = new AuthService($user);
        $authService->removeAllSession();
        return response([
            'data' => true
        ]);
    }
}
