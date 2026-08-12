<?php

declare(strict_types=1);

namespace app\middleware;

use app\support\AuthSession;
use app\support\Web;
use Closure;
use think\Request;
use think\Response;
use think\facade\Db;
use think\facade\Session;

final class RequireMerchant
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = (int) Session::get('merchant_user_id', 0);
        $merchantId = (int) Session::get('merchant_id', 0);
        if ($userId <= 0 || $merchantId <= 0) {
            return Web::redirect('/login', '请先登录商家工作台。', 'error');
        }

        $user = Db::name('merchant_users')->where('id', $userId)->where('merchant_id', $merchantId)->find();
        $merchant = Db::name('merchants')->where('id', $merchantId)->find();
        if (!$user || !$merchant || $user['status'] !== 'active' || $merchant['status'] !== 'active') {
            Session::clear();
            return Web::redirect('/login', '账号不可用，请联系管理员。', 'error');
        }
        if (!AuthSession::merchantIsCurrent($user)) {
            Session::clear();
            return Web::redirect('/login', '登录状态已失效，请使用当前密码重新登录。', 'error');
        }

        return $next($request);
    }
}
