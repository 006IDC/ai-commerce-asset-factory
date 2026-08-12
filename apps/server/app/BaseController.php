<?php
declare (strict_types = 1);

namespace app;

use app\service\MerchantWorkspaceService;
use app\service\PlanService;
use app\service\AdminShellService;
use app\support\Web;
use Throwable;
use think\App;
use think\Response;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\Session;
use think\Validate;

/**
 * 控制器基础类
 */
abstract class BaseController
{
    /**
     * Request实例
     * @var \think\Request
     */
    protected $request;

    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;

    /**
     * 是否批量验证
     * @var bool
     */
    protected $batchValidate = false;

    /**
     * 控制器中间件
     * @var array
     */
    protected $middleware = [];

    /**
     * 构造方法
     * @access public
     * @param  App  $app  应用对象
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;

        // 控制器初始化
        $this->initialize();
    }

    // 初始化
    protected function initialize()
    {}

    /**
     * 验证数据
     * @access protected
     * @param  array        $data     数据
     * @param  string|array $validate 验证器名或者验证规则数组
     * @param  array        $message  提示信息
     * @param  bool         $batch    是否批量验证
     * @return array|string|true
     * @throws ValidateException
     */
    protected function validate(array $data, string|array $validate, array $message = [], bool $batch = false)
    {
        if (is_array($validate)) {
            $v = new Validate();
            $v->rule($validate);
        } else {
            if (strpos($validate, '.')) {
                // 支持场景
                [$validate, $scene] = explode('.', $validate);
            }
            $class = false !== strpos($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);
            $v     = new $class();
            if (!empty($scene)) {
                $v->scene($scene);
            }
        }

        $v->message($message);

        // 是否批量验证
        if ($batch || $this->batchValidate) {
            $v->batch(true);
        }

        return $v->failException(true)->check($data);
    }

    protected function render(string $template, array $data = []): Response
    {
        $merchantFailedJobCount = 0;
        $workspaceRecentProjects = [];
        $workspacePlanName = '基础方案';
        $merchantId = (int) Session::get('merchant_id');
        $currentPath = '/' . ltrim($this->request->pathinfo(), '/');
        if ($merchantId > 0) {
            try {
                $merchantFailedJobCount = Db::name('generation_jobs')
                    ->where('merchant_id', $merchantId)
                    ->whereIn('status', ['failed', 'partial', 'result_missing'])
                    ->whereNull('merchant_hidden_at')
                    ->count();
            } catch (Throwable) {
                // Keep workspace pages available if an installation is incomplete.
            }
            if (str_starts_with($currentPath, '/workspace')) {
                try {
                    $workspaceRecentProjects = $this->app->make(MerchantWorkspaceService::class)->sidebarProjects($merchantId);
                    $subscription = $this->app->make(PlanService::class)->currentSubscription($merchantId);
                    $workspacePlanName = trim((string) ($subscription['plan_name'] ?? '')) ?: '基础方案';
                } catch (Throwable) {
                    // The creation shell remains usable while an upgrade is in progress.
                }
            }
        }
        $common = [
            'csrfToken' => Web::csrfToken(),
            'flashSuccess' => Session::get('success'),
            'flashError' => Session::get('error'),
            'currentPath' => $currentPath,
            'merchantFailedJobCount' => $merchantFailedJobCount,
            'workspaceRecentProjects' => $workspaceRecentProjects,
            'workspacePlanName' => $workspacePlanName,
            'workspaceProjectTitle' => trim((string) ($data['workspaceProjectTitle'] ?? $data['pageTitle'] ?? '创作工作台')),
            'workspaceSaveState' => trim((string) ($data['workspaceSaveState'] ?? '已自动保存')),
        ];
        $adminId = (int) Session::get('admin_user_id');
        if ($adminId > 0 && str_starts_with($currentPath, '/admin')) {
            try {
                $shell = $this->app->make(AdminShellService::class);
                $pageTitle = trim((string) ($data['pageTitle'] ?? '管理后台'));
                $shell->recordVisit($adminId, $currentPath, $pageTitle);
                $navigationState = $shell->recentAndFavorites($adminId);
                $taskReminder = $shell->taskReminder();
                $common += [
                    'adminNavigation' => $shell->navigation($currentPath),
                    'adminBreadcrumbs' => $shell->breadcrumbs($currentPath, $pageTitle),
                    'adminTaskReminder' => $taskReminder,
                    'adminTodos' => (array) ($taskReminder['items'] ?? []),
                    'adminRecent' => $navigationState['recent'],
                    'adminFavorites' => $navigationState['favorites'],
                    'adminCurrentFavorite' => (bool) array_filter(
                        $navigationState['favorites'],
                        static fn (array $item): bool => (string) $item['path'] === $currentPath
                    ),
                ];
            } catch (Throwable) {
                $common += [
                    'adminNavigation' => [],
                    'adminBreadcrumbs' => [],
                    'adminTaskReminder' => ['available' => false, 'total' => 0, 'items' => []],
                    'adminTodos' => [],
                    'adminRecent' => [],
                    'adminFavorites' => [],
                    'adminCurrentFavorite' => false,
                ];
            }
        }

        return Response::create($template, 'view')->assign(array_merge($common, $data));
    }

}
