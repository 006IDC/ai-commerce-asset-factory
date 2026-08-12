<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\PublicReleaseService;
use think\Response;
use think\facade\Session;

final class PublicSite extends BaseController
{
    public function releases(PublicReleaseService $releases): Response
    {
        $filter = trim((string) $this->request->get('filter', 'all'));
        $filter = array_key_exists($filter, $releases->filters()) ? $filter : 'all';

        return $this->render('/releases', [
            'pageTitle' => '版本历程',
            'signedIn' => Session::has('merchant_user_id'),
            'publicReleases' => $releases->filtered($filter),
            'publicReleaseFilters' => $releases->filters(),
            'activeReleaseFilter' => $filter,
            'publicVersion' => $releases->publicVersion(),
        ]);
    }
}
